<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class AccountBalance extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

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
