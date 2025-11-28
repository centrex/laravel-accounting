<?php

namespace Centrex\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYear extends Model
{
    protected $fillable = [
        'name', 'start_date', 'end_date', 'is_closed', 'is_current'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
        'is_current' => 'boolean',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }

    public static function getCurrent(): ?self
    {
        return static::where('is_current', true)->first();
    }
}