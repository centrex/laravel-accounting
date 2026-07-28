<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Owner extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasFactory;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'owners';
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
        'code',
        'name',
        'email',
        'ownership_percentage',
        'capital_account_id',
        'drawings_account_id',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
        'is_active'            => 'boolean',
    ];

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'capital_account_id');
    }

    public function drawingsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'drawings_account_id');
    }

    /**
     * Net equity = capital balance + drawings balance.
     *
     * This is "+" not "-" on purpose: Account::getCurrentBalance() normalizes by account
     * type, but only asset/expense accounts are treated as debit-normal (isDebitAccount()).
     * The Drawings account is type=equity, so its balance is computed as credits − debits —
     * which comes out negative once real withdrawals (debits) are posted. Adding that
     * (already-negative) drawings balance to the (positive) capital balance nets correctly.
     */
    public function equityBalance(): float
    {
        return $this->capitalAccount->getCurrentBalance() + $this->drawingsAccount->getCurrentBalance();
    }
}
