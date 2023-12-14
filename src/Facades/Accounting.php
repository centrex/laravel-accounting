<?php

declare(strict_types=1);

namespace Centrex\LaravelAccounting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Centrex\LaravelAccounting\Accounting
 */
class Accounting extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Centrex\LaravelAccounting\Accounting::class;
    }
}
