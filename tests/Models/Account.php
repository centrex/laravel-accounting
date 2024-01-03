<?php

declare(strict_types = 1);

namespace Models;

use Centrex\LaravelAccounting\ModelTraits\HasAccountingJournal;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Account
 *
 * @property    int         $id
 * @property 	string		$name
 */
class Account extends Model
{
    use HasAccountingJournal;
}
