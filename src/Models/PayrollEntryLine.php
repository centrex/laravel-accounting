<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Traits\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model};
use Illuminate\Database\Eloquent\Relations\{BelongsTo};

class PayrollEntryLine extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'payroll_entries_lines';
    }

    protected $fillable = [
        'payroll_entry_id', 'employee_id', 'type',
        'amount', 'description', 'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
