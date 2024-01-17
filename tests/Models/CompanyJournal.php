<?php

declare(strict_types = 1);

namespace Models;

use Centrex\LaravelAccounting\ModelTraits\HasAccountingJournal;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Account
 *
 * NOTE: This is only used for testing purposes.  A Company Journals table is not needed, and is simply a way to create some objects that have journals attached to them for accounting purposes.  This is best illustrated by studying the tests; but the most important thing to rememeber is that this is entirely optional and only one way of adding journals to "meanningless" objects (Company Journals), or you could, if you wanted, add functions to a company hournal model.
 *
 * @property int $id
 * @property string $name
 */
class CompanyJournal extends Model
{
    use HasAccountingJournal;
}
