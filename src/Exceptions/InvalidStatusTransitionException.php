<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class InvalidStatusTransitionException extends AccountingException
{
    public static function make(string $model, string $from, string $to): self
    {
        return new self("Cannot transition {$model} from '{$from}' to '{$to}'.");
    }
}
