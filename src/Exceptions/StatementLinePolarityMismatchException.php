<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class StatementLinePolarityMismatchException extends AccountingException
{
    public static function make(string $statementLineType, string $glLineType): self
    {
        return new self(
            sprintf(
                'Statement line type "%s" does not match journal line type "%s". A bank statement debit pairs with a GL debit line, and a credit pairs with a GL credit line.',
                $statementLineType,
                $glLineType,
            ),
        );
    }
}
