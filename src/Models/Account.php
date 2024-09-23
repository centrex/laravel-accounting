<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\ModelTraits\HasAccountingJournal;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasAccountingJournal;

    protected $guarded = [];

    /** @var string */
    protected $table = 'accounts';

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection'));
    }
}
