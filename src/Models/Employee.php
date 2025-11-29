<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

// Employee Model
class Employee extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'employees';
    }
}
