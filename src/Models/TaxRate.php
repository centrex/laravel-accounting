<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class TaxRate extends Model implements Auditable
{
    use AuditableTrait;
    use AddTablePrefix;
    use HasFactory;
    use SoftDeletes;

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
        'name', 'code', 'rate', 'is_compound', 'is_active',
    ];

    protected $casts = [
        'rate'        => 'decimal:2',
        'is_compound' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function billItems(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }
}
