<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEntry extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'payroll_entries';
    }

    protected $fillable = [
        'entry_number', 'date', 'description', 'total_amount', 'status',
    ];

    protected $casts = [
        'date'         => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }
}
