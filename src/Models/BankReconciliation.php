<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\BankReconciliationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class BankReconciliation extends Model implements Auditable
{
    use AuditableTrait;
    use AddTablePrefix;
    use HasFactory;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'bank_reconciliations';
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
        'account_id', 'statement_date', 'opening_balance', 'statement_ending_balance',
        'status', 'reconciled_by', 'reconciled_at', 'notes',
    ];

    protected $casts = [
        'statement_date'           => 'date',
        'opening_balance'          => 'decimal:2',
        'statement_ending_balance' => 'decimal:2',
        'status'                   => BankReconciliationStatus::class,
        'reconciled_at'            => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function reconciledLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }
}
