<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Exceptions;

class UnbalancedJournalException extends AccountingException
{
    public static function create(): self
    {
        return new self('Journal entry is not balanced. Debits must equal credits.');
    }
}
