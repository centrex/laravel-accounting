<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class StatementLineAlreadyMatchedException extends AccountingException
{
    public static function forLine(int $id): self
    {
        return new self("Statement line #{$id} is already matched. Unmatch it first.");
    }

    public static function forGlLine(int $id): self
    {
        return new self("Journal entry line #{$id} is already reconciled against another statement line.");
    }
}
