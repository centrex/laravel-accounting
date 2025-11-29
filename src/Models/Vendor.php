<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use Illuminate\Support\Facades\DB;

class Vendor extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'vendors';
    }

    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'tax_id',
        'currency',
        'payment_terms',
        'is_active',
        'modelable_type',
        'modelable_id',
    ];

    protected $casts = [
        'payment_terms' => 'integer',
        'is_active'     => 'boolean',
    ];

    /**
     * Polymorphic relation: Vendor can belong to any model (User, Company, Tenant, etc.)
     */
    public function modelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function getTotalOutstandingAttribute(): float
    {
        return (float) $this->bills()
            ->whereIn('status', ['approved', 'partial', 'overdue'])
            ->sum(DB::raw('total - paid_amount'));
    }
}
