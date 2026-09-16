<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\CreditMemoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class CreditMemo extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasFactory;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'credit_memos';
    }

    /** Specify the connection, since this implements multitenant solution. */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'credit_memo_number', 'invoice_id', 'customer_id', 'credit_memo_date', 'reason',
        'currency', 'exchange_rate', 'subtotal', 'tax_amount', 'total', 'amount_refunded',
        'status', 'journal_entry_id', 'source_type', 'source_id', 'source_reference',
        'sbu_code', 'notes', 'created_by', 'issued_by', 'issued_at',
    ];

    protected $casts = [
        'credit_memo_date' => 'date',
        'status'           => CreditMemoStatus::class,
        'subtotal'         => 'decimal:2',
        'tax_amount'       => 'decimal:2',
        'total'            => 'decimal:2',
        'amount_refunded'  => 'decimal:2',
        'exchange_rate'    => 'decimal:6',
        'issued_at'        => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $creditMemo): void {
            if ($creditMemo->credit_memo_number) {
                return;
            }

            DB::connection($creditMemo->getConnectionName())->transaction(function () use ($creditMemo) {
                $date = now()->format('Ymd');

                $lastMemo = self::withTrashed()
                    ->orderByDesc('id') // safer than credit_memo_number
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($lastMemo && preg_match('/(\d+)$/', $lastMemo->credit_memo_number, $m)) {
                    $sequence = ((int) $m[1]) + 1;
                }

                $creditMemo->credit_memo_number = sprintf(
                    'CM-%s-%05d',
                    $date,
                    $sequence,
                );
            });
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Cash refunds paid out against this memo (see Accounting::recordCreditMemoRefund()). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')
            ->where('payable_type', self::class);
    }

    /**
     * Amount still owed to the customer in cash.
     *
     * Issuing a memo only credits AR — it never implies cash changed hands, so a memo
     * against an invoice the customer hasn't paid has nothing to refund (the return just
     * lowers what they owe). This caps `total - amount_refunded` by the cash actually
     * received on the invoice, net of everything already refunded against any of its
     * credit memos (including this one), so an unpaid (or only partially paid) invoice's
     * memo can't be refunded out of cash the company never took in.
     */
    public function getRefundableAmountAttribute(): float
    {
        $remaining = round((float) $this->total - (float) $this->amount_refunded, 2);

        if ($remaining <= 0 || !$this->invoice) {
            return max(0.0, $remaining);
        }

        $totalRefundedOnInvoice = (float) $this->invoice->creditMemos()->sum('amount_refunded');
        $cashAvailable = round((float) $this->invoice->paid_amount - $totalRefundedOnInvoice, 2);

        return max(0.0, min($remaining, $cashAvailable));
    }
}
