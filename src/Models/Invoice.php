<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\EntryStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'invoices';
    }

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'invoice_number', 'customer_id', 'invoice_date', 'due_date',
        'subtotal', 'tax_amount', 'discount_amount', 'total',
        'paid_amount', 'currency', 'exchange_rate', 'status', 'notes', 'journal_entry_id',
        'inventory_sale_order_id',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'status'          => EntryStatus::class,
        'due_date'        => 'date',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total'           => 'decimal:2',
        'paid_amount'     => 'decimal:2',
        'exchange_rate'   => 'decimal:6',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $invoice): void {
            if ($invoice->invoice_number) {
                return;
            }

            DB::connection($invoice->getConnectionName())->transaction(function () use ($invoice) {
                $date = now()->format('Ymd');

                $lastInvoice = self::query()
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id') // safer than invoice_number
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($lastInvoice && preg_match('/(\d+)$/', $lastInvoice->invoice_number, $m)) {
                    $sequence = ((int) $m[1]) + 1;
                }

                $invoice->invoice_number = sprintf(
                    'INV-%s-%05d',
                    $date,
                    $sequence,
                );
            });
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')
            ->where('payable_type', self::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function getBalanceAttribute(): float
    {
        return $this->total - $this->paid_amount;
    }
}
