<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Centrex\LaravelAccounting\Enums\AccountSubtype;
use Centrex\LaravelAccounting\Enums\AccountType;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

// Account Model
class Account extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'accounts';
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
        'code', 'name', 'type', 'subtype', 'parent_id',
        'description', 'currency', 'is_active', 'is_system', 'level',
    ];

    protected $casts = [
        'type'      => AccountType::class,
        'subtype'   => AccountSubtype::class,
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'level'     => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    // Get current balance
    public function getCurrentBalance(): float
    {
        $debits = $this->journalEntryLines()
            ->whereHas('journalEntry', function ($q): void {
                $q->where('status', 'posted');
            })
            ->where('type', 'debit')
            ->sum('amount');

        $credits = $this->journalEntryLines()
            ->whereHas('journalEntry', function ($q): void {
                $q->where('status', 'posted');
            })
            ->where('type', 'credit')
            ->sum('amount');

        // Normal balance depends on account type
        if (in_array($this->type, ['asset', 'expense'])) {
            return $debits - $credits; // Debit normal balance
        }

        return $credits - $debits; // Credit normal balance
    }

    // Check if account has normal debit balance
    public function isDebitAccount(): bool
    {
        return in_array($this->type, ['asset', 'expense']);
    }
}
