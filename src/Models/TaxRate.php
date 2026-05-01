<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class TaxRate extends Model implements Auditable
{
    use AuditableTrait;
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'tax_rates';
    }

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'name', 'rate', 'description',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];
}
