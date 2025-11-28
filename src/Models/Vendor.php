<?php

namespace Centrex\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vendor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'email', 'phone', 'address', 'city', 'country',
        'tax_id', 'currency', 'payment_terms', 'is_active'
    ];

    protected $casts = [
        'payment_terms' => 'integer',
        'is_active' => 'boolean',
    ];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function getTotalOutstandingAttribute(): float
    {
        return $this->bills()
            ->whereIn('status', ['approved', 'partial', 'overdue'])
            ->sum(\DB::raw('total - paid_amount'));
    }
}