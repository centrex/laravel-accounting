<?php

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Traits\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model};

class Payment extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'payments';
    }
}