<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

/**
 * A journal is a record of a transactions for a single parent model instance.
 */

use Carbon\{Carbon, CarbonInterface};
use Centrex\LaravelAccounting\Casts\{CurrencyCast, MoneyCast};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
use Money\{Currency, Money};

/**
 * @property Money $balance
 * @property string $currency_code ISO 4217
 * @property Currency $currency
 * @property CarbonInterface $updated_at
 * @property CarbonInterface $post_date
 * @property CarbonInterface $created_at
 * @property Model $morphed deprecated; use owner
 * @property Model $owner
 * @property Ledger|null $ledger
 */
class Journal extends Model
{
    /** @var string */
    protected $table = 'accounting_journals';

    /** @var array */
    protected $casts = [
        'currency' => CurrencyCast::class . ':currency_code',
        'balance'  => MoneyCast::class . ':currency_code,balance',
    ];

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection'), config('database.default'));
    }

    /**
     * Relationship to all the model instance this journal applies to.
     *
     * @todo use owner
     */
    public function morphed(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model instance this journal applies to.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'morphed_type', 'morphed_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(config('accounting.model-classes.ledger'));
    }

    protected static function boot()
    {
        parent::boot();

        // @todo when created, there will be no transactions and so no balance.
        // Instead, set the balance default to zero though an attribute.

        static::created(
            fn (Journal $journal) => $journal->resetCurrentBalance(),
        );
    }

    /** @todo make sure the currencies match. */
    public function assignToLedger(Ledger $ledger): self
    {
        $ledger->journals()->save($this);

        return $this;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(config('accounting.model-classes.journal-transaction'));
    }

    public function resetCurrentBalance(): Money
    {
        $this->balance = $this->totalBalance();
        $this->save();

        // Log::debug('Updating ledger balance', ['journalId' => $this->id, 'balance' => $this->balance]);

        return $this->balance;
    }

    /**
     * Get the debit only balance of the journal at the end of a given day.
     */
    public function debitBalanceOn(CarbonInterface $date): Money
    {
        $balanceMinorUnits = $this->transactions()
            ->where('post_date', '<=', $date->endOfDay())
            ->where('currency_code', '=', $this->currency_code)
            ->sum('debit') ?: 0;

        return new Money($balanceMinorUnits, $this->currency);
    }

    /**
     * Get the credit only balance of the journal at the end of a given day.
     */
    public function creditBalanceOn(CarbonInterface $date): Money
    {
        $balanceMinorUnits = $this->transactions()
            ->where('post_date', '<=', $date->endOfDay())
            ->where('currency_code', '=', $this->currency_code)
            ->sum('credit') ?: 0;

        return new Money($balanceMinorUnits, $this->currency);
    }

    /**
     * Get the balance of the journal for a given date.
     */
    public function balanceOn(CarbonInterface $date): Money
    {
        return $this->creditBalanceOn($date)->subtract($this->debitBalanceOn($date));
    }

    /**
     * Get the balance of the journal today, excluding future transactions (after today).
     */
    public function currentBalance(): Money
    {
        return $this->balanceOn(Carbon::now());
    }

    /**
     * Get the balance of the journal taking all transactions into account.
     * This *could* include future dates.
     */
    public function totalBalance(): Money
    {
        $creditBalanceMinorUnits = (int) $this->transactions()
            ->where('currency_code', '=', $this->currency_code)
            ->sum('credit');

        $debitBalanceMinorUnits = (int) $this->transactions()
            ->where('currency_code', '=', $this->currency_code)
            ->sum('debit');

        $balance = $creditBalanceMinorUnits - $debitBalanceMinorUnits;

        return new Money($balance, $this->currency);
    }

    /**
     * Remove matching journal entries.
     *
     * We want to remove transactions that match:
     *
     * - The given reference.
     * - Any other arbitrary conditions (a query callback can do this).
     *
     * Some thought on how transaction groups would be handled is needed.
     * Maybe for now only allow removal of entries that are no in a ledger.
     *
     * @return void
     */
    public function remove()
    {
    }

    /**
     * Create a credit journal entry.
     *
     * @param  Money|int  $value
     */
    public function credit(
        $value,
        ?string $memo = null,
        ?CarbonInterface $post_date = null,
        ?string $transaction_group = null,
    ): JournalTransaction {
        $value = is_a($value, Money::class)
            ? $value->absolute()
            : new Money(abs($value), $this->currency);

        return $this->post($value, null, $memo, $post_date, $transaction_group);
    }

    /**
     * Debit the journal with a new entry.
     *
     * @param  Money|int  $value
     */
    public function debit(
        $value,
        ?string $memo = null,
        ?CarbonInterface $post_date = null,
        ?string $transaction_group = null,
    ): JournalTransaction {
        $value = is_a($value, Money::class)
            ? $value->absolute()
            : new Money(abs($value), $this->currency);

        return $this->post(null, $value, $memo, $post_date, $transaction_group);
    }

    /**
     * Create a journal entry (a debit or a credit).
     *
     * @todo make sure the correct currency has been supplied.
     */
    private function post(
        ?Money $credit = null,
        ?Money $debit = null,
        ?string $memo = null,
        ?CarbonInterface $postDate = null,
        ?string $transactionGroup = null,
    ): JournalTransaction {
        /** @var string */
        $transactionClass = config('accounting.model-classes.journal-transaction');
        $transaction      = new $transactionClass();

        $transaction->credit = $credit;
        $transaction->debit  = $debit;

        // @todo use the journal currency, after confirming the correct
        // currency has been passed in.

        $currency = $credit?->getCurrency() ?? $debit->getCurrency();

        $transaction->memo = $memo;
        // @todo the transaction needs to cast currency to an object,
        // so this will change to: `$transaction->currency = $this->currency`
        $transaction->currency          = $currency;
        $transaction->post_date         = $postDate ?: Carbon::now();
        $transaction->transaction_group = $transactionGroup;

        $this->transactions()->save($transaction);

        return $transaction;
    }
}
