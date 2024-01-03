<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Exceptions;

use Exception;

class BaseException extends Exception
{
    public function __construct($message = null)
    {
        parent::__construct($message ?: $this->message, 0, null);
    }
}
