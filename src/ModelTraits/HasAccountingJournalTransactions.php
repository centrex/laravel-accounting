<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\ModelTraits;

/**
 * Trait for models that have journal transactions referencing them.
 */

use Centrex\LaravelAccounting\Models\JournalTransaction;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\{Collection, Model};

/**
 * @mixin Model
 *
 * @property Collection<JournalTransaction> $journalTransactions
 */
trait HasAccountingJournalTransactions
{
    /**
     * A model may have journal transactions referencing it.
     */
    public function journalTransactions(): MorphMany
    {
        return $this->morphMany(config('accounting.model-classes.journal-transaction'), 'reference');
    }
}
