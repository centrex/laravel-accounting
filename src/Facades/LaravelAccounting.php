<?php

declare(strict_types=1);

namespace Centrex\LaravelAccounting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Centrex\LaravelAccounting\LaravelAccounting
 */
class LaravelAccounting extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Centrex\LaravelAccounting\LaravelAccounting::class;
    }
}
