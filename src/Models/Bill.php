<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Traits\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Bill extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'bills';
    }

    protected $fillable = [
        'bill_number', 'vendor_id', 'date', 'due_date', 'total_amount', 'status',
    ];

    protected $casts = [
        'date'         => 'date',
        'due_date'     => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
