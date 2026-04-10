<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Centrex\Accounting\Accounting
 */
class Accounting extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Centrex\Accounting\Accounting::class;
    }
}
