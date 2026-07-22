<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class ReconciliationBalanceMismatchException extends AccountingException
{
    public static function make(float $expected, float $actual, float $variance): self
    {
        return new self(
            sprintf(
                'Reconciliation balance mismatch. Expected: %.2f, Actual: %.2f, Variance: %.2f.',
                $expected,
                $actual,
                $variance,
            ),
        );
    }
}
