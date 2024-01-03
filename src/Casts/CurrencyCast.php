<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Money\Currency;

class CurrencyCast implements CastsAttributes
{
    public function __construct(protected ?string $columnName = null)
    {
        // The column name can override the key.
    }

    public function get($model, string $key, $value, array $attributes)
    {
        $value = $value ?: $attributes[$this->columnName ?? $key];

        return $value ? new Currency($value) : null;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return [$this->columnName ?? $key => $value?->getCode()];
    }
}
