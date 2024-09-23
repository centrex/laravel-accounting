<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\ModelTraits\HasAccountingJournal;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasAccountingJournal;

    protected $guarded = [];
}
