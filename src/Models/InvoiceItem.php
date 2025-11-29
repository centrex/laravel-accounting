<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class InvoiceItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'invoice_items';
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
        'invoice_id',
        'description',
        'itemable_type',
        'itemable_id',
        'quantity',
        'unit_price',
        'amount',
        'tax_rate',
        'tax_amount',
        'reference',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
        'tax_rate'   => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    /**
     * Polymorphic relation — may be null.
     * Example: Product, Service, etc.
     */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Invoice relationship.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Convenience accessor: total for the line (amount + tax_amount).
     */
    public function getTotalAttribute(): float
    {
        return (float) ($this->amount + $this->tax_amount);
    }

    /**
     * Helper: recalc amount and tax_amount from quantity and unit_price + tax_rate.
     * Call before saving if you want auto-calculation.
     */
    public function recalcFromUnit(): void
    {
        $this->amount = (float) bcmul((string) $this->quantity, (string) $this->unit_price, 2);
        $this->tax_amount = (float) bcmul((string) $this->amount, bcdiv((string) $this->tax_rate, '100', 4), 2);
    }
}
