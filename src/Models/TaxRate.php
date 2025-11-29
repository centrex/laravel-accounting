<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'tax_rates';
    }

    protected $fillable = [
        'name', 'rate', 'description',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];
}
