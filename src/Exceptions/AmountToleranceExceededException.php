<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class AmountToleranceExceededException extends AccountingException
{
    public static function make(float $lineAmount, float $glAmount, float $tolerance): self
    {
        return new self(
            sprintf(
                'Statement line amount %.2f does not match journal line amount %.2f within tolerance %.2f.',
                $lineAmount,
                $glAmount,
                $tolerance,
            ),
        );
    }
}
