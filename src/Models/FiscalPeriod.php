<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class FiscalPeriod extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'fiscal_periods';
    }

    protected $fillable = [
        'fiscal_year_id', 'name', 'start_date', 'end_date', 'is_closed',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_closed'  => 'boolean',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function accountBalances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }
}
