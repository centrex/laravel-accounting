<?php

declare(strict_types=1);

namespace Centrex\LaravelAccounting\ModelTraits;

/**
 * A model that has an accounting journal.
 */

use Centrex\LaravelAccounting\Exceptions\JournalAlreadyExists;
use Centrex\LaravelAccounting\Models\Journal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Money\Currency;
use Money\Money;

/**
 * @mixin Model
 *
 * @property Journal $journal
 */
trait HasAccountingJournal
{
    public function journal(): MorphOne
    {
        return $this->morphOne(Journal::class, 'morphed');
    }

    /**
     * Initialize a new journal for this model instance.
     *
     * @param  null|string|Currency  $currency
     * @param  null|string  $ledgerId @todo should this not be an int?
     * @return mixed
     *
     * @throws JournalAlreadyExists
     */
    public function initJournal(
        mixed $currency = null,
        ?string $ledgerId = null,
    ) {
        if ($this->journal) {
            throw new JournalAlreadyExists;
        }

        if ($currency === null) {
            $currency = config('accounting.base_currency');
        }

        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        $journalClass = config('accounting.model-classes.journal');

        $journal = new $journalClass();

        $journal->ledger_id = $ledgerId;
        $journal->balance = new Money(0, $currency);

        return $this->journal()->save($journal);
    }
}
