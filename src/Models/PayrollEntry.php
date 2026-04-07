<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Centrex\LaravelAccounting\Enums\EntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEntry extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'payroll_entries';
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
        'entry_number', 'date', 'reference', 'description',
        'currency', 'type', 'exchange_rate',
        'created_by', 'approved_by', 'approved_at', 'status',
    ];

    protected $casts = [
        'date'          => 'date',
        'approved_at'   => 'datetime',
        'status'        => EntryStatus::class,
        'exchange_rate' => 'decimal:6',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }
}
