<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Carbon\{Carbon, CarbonInterface};
use Centrex\LaravelAccounting\Enums\LedgerType;
use Centrex\LaravelAccounting\ModelTraits\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, HasManyThrough};
use Money\{Currency, Money};

/**
 * @property Money $balance
 * @property Carbon $updated_at
 * @property Carbon $post_date
 * @property Carbon $created_at
 */
class Ledger extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix()
    {
        return 'ledgers';
    }

    protected $casts = [
        'type' => LedgerType::class,
    ];

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection'));
    }

    public function journals(): HasMany
    {
        return $this->hasMany(config('accounting.model-classes.journal'));
    }

    /** Get all of the posts for the country. */
    public function journal_transactions(): HasManyThrough
    {
        return $this->hasManyThrough(config('accounting.model-classes.journal-transaction'), Journal::class);
    }

    public function balanceOn(string $currency, CarbonInterface $start, CarbonInterface $end): Money
    {
        $currency = new Currency($currency);

        $debit = $this->journal_transactions
            ->when($start, fn ($query) => $query->where('post_date', '>=', $start->startOfDay()))
            ->when($end, fn ($query) => $query->where('post_date', '<=', $end->endOfDay()))
            ->reduce(fn ($carry, JournalTransaction $transaction) => $transaction->debit ? $carry->add($transaction->debit) : $carry,
                new Money(0, $currency),
            );

        $credit = $this->journal_transactions
            ->when($start, fn ($query) => $query->where('post_date', '>=', $start->startOfDay()))
            ->when($end, fn ($query) => $query->where('post_date', '<=', $end->endOfDay()))
            ->reduce(fn ($carry, JournalTransaction $transaction) => $transaction->credit ? $carry->add($transaction->credit) : $carry,
                new Money(0, $currency),
            );

        if ($this->type === LedgerType::ASSET || $this->type === LedgerType::EXPENSE) {
            return $debit->subtract($credit);
        }

        return $credit->subtract($debit);
    }

    /**
     * Sum up all balances for all journals in this ledger.
     *
     * This relies on all balances being saved to the journals.
     *
     * @todo protect the sum from accidentally mixing currencies.
     * @todo this is possibly *total* balance, rather than *current* balance.
     * The journals hold the total balance that includes future transactions.
     * @todo are the ledger account types even grouped properly here?
     * @todo accept currency object rather than a code.
     */
    public function currentBalance(string $currency): Money
    {
        $currency = new Currency($currency);

        $debit = $this->journal_transactions->reduce(
            fn ($carry, JournalTransaction $transaction) => $transaction->debit ? $carry->add($transaction->debit) : $carry,
            new Money(0, $currency),
        );

        $credit = $this->journal_transactions->reduce(
            fn ($carry, JournalTransaction $transaction) => $transaction->credit ? $carry->add($transaction->credit) : $carry,
            new Money(0, $currency),
        );

        if ($this->type === LedgerType::ASSET || $this->type === LedgerType::EXPENSE) {
            return $debit->subtract($credit);
        }

        return $credit->subtract($debit);
    }
}
