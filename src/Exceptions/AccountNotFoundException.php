<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Exceptions;

class AccountNotFoundException extends AccountingException
{
    public static function forCode(string $code): self
    {
        return new self("Account with code {$code} not found.");
    }
}
