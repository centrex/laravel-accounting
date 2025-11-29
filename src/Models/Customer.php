<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'customers';
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
        'code',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'tax_id',
        'currency',
        'credit_limit',
        'payment_terms',
        'is_active',
        'modelable_type',
        'modelable_id',
    ];

    protected $casts = [
        'credit_limit'  => 'decimal:2',
        'payment_terms' => 'integer',
        'is_active'     => 'boolean',
    ];

    /**
     * Polymorphic relation: Customer belongs to any model (User, Company, App\Models\Tenant, etc.)
     */
    public function modelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function getTotalOutstandingAttribute(): float
    {
        return (float) $this->invoices()
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum(DB::raw('total - paid_amount'));
    }
}
