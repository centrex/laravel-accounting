<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Traits\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model};
use Illuminate\Database\Eloquent\Relations\{HasMany};

class FiscalYear extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'fiscal_years';
    }

    protected $fillable = [
        'name', 'start_date', 'end_date', 'is_closed', 'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_closed'  => 'boolean',
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
