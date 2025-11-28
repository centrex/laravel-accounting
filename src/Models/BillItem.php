<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Traits\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class BillItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'bill_items';
    }

    protected $fillable = [
        'bill_id',
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
     * Polymorphic reference (nullable).
     * Example: Product, Service, or any other model.
     */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * Auto-calculated total amount for convenience.
     */
    public function getTotalAttribute(): float
    {
        return (float) ($this->amount + $this->tax_amount);
    }
}
