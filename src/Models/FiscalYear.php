<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class FiscalYear extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

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
