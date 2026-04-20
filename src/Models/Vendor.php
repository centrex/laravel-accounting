<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Concerns\HasPrimaryImage;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use Spatie\MediaLibrary\HasMedia;

class Vendor extends Model implements HasMedia
{
    use AddTablePrefix;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'vendors';
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
        'payment_terms',
        'is_active',
        'modelable_type',
        'modelable_id',
    ];

    protected $casts = [
        'payment_terms' => 'integer',
        'is_active'     => 'boolean',
    ];

    protected $appends = [
        'primary_image_url',
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
            ->whereIn('status', ['issued', 'partially_settled', 'overdue'])
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as outstanding')
            ->value('outstanding');
    }
}
