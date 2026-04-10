<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Concerns;

use Centrex\LaravelAccounting\Enums\Currency;

trait WithCurrency
{
    private static ?string $cachedCurrency = null;

    protected static function getCurrency(): string
    {
        return self::$cachedCurrency ??= config('accounting.base_currency', 'BDT');
    }

    protected static function getCurrencyEnum(): Currency
    {
        return Currency::tryFrom(self::getCurrency()) ?? Currency::BDT;
    }

    protected static function formatCurrency(float|int|string $amount, ?string $currency = null): string
    {
        $code = $currency ?? self::getCurrency();
        $enum = Currency::tryFrom(strtoupper($code));

        if ($enum !== null) {
            return $enum->format((float) $amount);
        }

        return $code . ' ' . number_format((float) $amount, 2);
    }

    protected static function formatCurrencySigned(float|int|string $amount, ?string $currency = null): string
    {
        $amount = (float) $amount;

        return self::formatCurrency(abs($amount), $currency) . ($amount < 0 ? ' DR' : '');
    }
}
