<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class UnbalancedJournalException extends AccountingException
{
    public static function create(): self
    {
        return new self('Journal entry is not balanced. Debits must equal credits.');
    }

    /** @param \Centrex\Accounting\Models\JournalEntry $entry */
    public static function make(object $entry): self
    {
        $debits = $entry->lines->where('type', 'debit')->sum('amount');
        $credits = $entry->lines->where('type', 'credit')->sum('amount');

        return new self(
            sprintf(
                'Journal entry is not balanced. Debits: %.2f, Credits: %.2f, Difference: %.2f.',
                $debits,
                $credits,
                abs($debits - $credits),
            ),
        );
    }
}
