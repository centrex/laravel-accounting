<?php

declare(strict_types = 1);

namespace Models;

use Centrex\LaravelAccounting\ModelTraits\HasAccountingJournal;
use Illuminate\Database\Eloquent\Model;

/**
 * Class User
 *
 * NOTE: This is only used for testing purposes.  It's not required for us
 *
 * @property    int                     $id
 * @property 	HasAccountingJournal		$journal
 */
class User extends Model
{
    use HasAccountingJournal;
}
