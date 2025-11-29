<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBalance extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'account_balances';
    }

    protected $fillable = [
        'account_id', 'fiscal_period_id', 'debit', 'credit', 'balance',
    ];

    protected $casts = [
        'debit'   => 'decimal:2',
        'credit'  => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }
}
