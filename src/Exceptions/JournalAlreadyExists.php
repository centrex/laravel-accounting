<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Exceptions;

class JournalAlreadyExists extends BaseException
{
    public $message = 'Journal already exists.';
}
