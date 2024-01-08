<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Money\{Currency, Money};

class MoneyCast implements CastsAttributes
{
    public function __construct(
        protected ?string $currencyColumn = null,
        protected ?string $amountColumn = null,
    ) {
    }

    /**
     * The default column names are $key + '_currency' and $key + '_amount'.
     * Both can be overridden by passing the column names as cast arguments.
     *
     * @param [type] $model
     * @param [type] $value
     * @return void
     */
    public function get($model, string $key, $value, array $attributes)
    {
        $currencyCode = $attributes[$this->currencyColumn ?? $key . '_currency'] ?? null;
        $minorUnits   = $attributes[$this->amountColumn ?? $key . '_amount']     ?? null;

        if ($currencyCode === null || $minorUnits === null) {
            return null;
        }

        return new Money($minorUnits, new Currency($currencyCode));
    }

    /**
     * @todo do not allow currency to be changed once set, because multiple
     * amounts may be sharing the same currency column.
     *
     * @param [type] $model
     * @param [type] $value
     * @return void
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null) {
            return [
                $this->currencyColumn ?? $key . '_currency' => null,
                $this->amountColumn   ?? $key . '_amount'   => null,
            ];
        }

        return [
            $this->currencyColumn ?? $key . '_currency' => $value?->getCurrency()?->getCode(),
            $this->amountColumn   ?? $key . '_amount'   => $value?->getAmount(),
        ];
    }
}
