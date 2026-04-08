<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Exceptions;

class OverpaymentException extends AccountingException
{
    public static function make(float $requested, float $outstanding): self
    {
        return new self(
            sprintf(
                'Payment amount %.2f exceeds outstanding balance %.2f.',
                $requested,
                $outstanding,
            ),
        );
    }
}
