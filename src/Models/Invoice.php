<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\EntryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphMany};
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Invoice extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasFactory;
    use SoftDeletes;

    /**
     * Expense account codes that reduce AR when recorded against an invoice: manual
     * sales discounts plus sale returns & allowances posted by the inventory ERP
     * integration. Canonical set — also used by InvoiceDetails and CustomerLedger.
     */
    public const AR_REDUCING_ACCOUNT_CODES = ['6130', '6131', '6132', '6133', '6134'];

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
        'subtotal', 'tax_amount', 'discount_amount', 'shipping_amount', 'total',
        'paid_amount', 'currency', 'exchange_rate', 'status', 'notes', 'journal_entry_id',
        'source_type', 'source_id', 'source_reference', 'sbu_code',
        'inventory_sale_order_id',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'status'          => EntryStatus::class,
        'due_date'        => 'date',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
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

                $lastInvoice = self::withTrashed()
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

    public function expenses(): MorphMany
    {
        return $this->morphMany(Expense::class, 'chargeable');
    }

    public function convertToBase(float|int|string|null $amount): float
    {
        $value = (float) ($amount ?? 0);
        $rate = (float) ($this->exchange_rate ?? 1);

        if ($rate <= 0) {
            $rate = 1.0;
        }

        return round($value * $rate, 2);
    }

    public function getBaseCurrencyAttribute(): string
    {
        return (string) config('accounting.base_currency', 'BDT');
    }

    public function getBaseSubtotalAttribute(): float
    {
        return $this->convertToBase($this->subtotal);
    }

    public function getBaseTaxAmountAttribute(): float
    {
        return $this->convertToBase($this->tax_amount);
    }

    public function getBaseDiscountAmountAttribute(): float
    {
        return $this->convertToBase($this->discount_amount);
    }

    public function getBaseShippingAmountAttribute(): float
    {
        return $this->convertToBase($this->shipping_amount);
    }

    public function getBaseTotalAttribute(): float
    {
        return $this->convertToBase($this->total);
    }

    public function getBasePaidAmountAttribute(): float
    {
        return $this->convertToBase($this->paid_amount);
    }

    public function getBaseBalanceAttribute(): float
    {
        return $this->convertToBase($this->balance);
    }

    public function getBalanceAttribute(): float
    {
        $discounts = $this->expenses()
            ->whereHas('account', fn ($q) => $q->whereIn('code', self::AR_REDUCING_ACCOUNT_CODES))
            ->sum('total');

        return round((float) $this->total - (float) $this->paid_amount - (float) $discounts, 2);
    }
}
