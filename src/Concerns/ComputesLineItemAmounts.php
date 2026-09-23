<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Models\TaxRate;

trait ComputesLineItemAmounts
{
    /**
     * Tax rates already resolved, keyed by application instance and then by rate id
     * (false records a lookup that found nothing, so misses are not re-queried either).
     *
     * Saving an invoice or bill creates its lines one at a time, and each line re-queried
     * the same handful of tax rates — a fresh query per line against a table that holds a
     * dozen rows and does not change mid-request.
     *
     * Keying by spl_object_id() of the container rather than using a flat static matters:
     * the trait's state would otherwise outlive the application it was populated under, and
     * every Testbench test case rebuilds the container over a fresh database where the same
     * rate ids denote different rates.
     *
     * @var array<int, array<int, TaxRate|false>>
     */
    private static array $resolvedTaxRates = [];

    /**
     * Compute `amount` from quantity/unit_price, and `tax_amount` from `tax_rate`.
     *
     * When `tax_rate_id` is set on a new line (or has just been changed on an
     * existing one), the linked TaxRate's current percentage is snapshotted into
     * `tax_rate` before the tax is calculated — so later edits to TaxRate::rate
     * never retroactively change an already-saved line. Re-saving an existing
     * line without touching `tax_rate_id` always uses the `tax_rate` already on
     * the record, which also covers the free-typed (no TaxRate) fallback path.
     *
     * `TaxRate::is_compound` is stored for reporting but not applied here: the
     * schema allows only one rate per line, so there is nothing for a second
     * rate to compound on top of yet.
     */
    protected function computeAmounts(object $item): void
    {
        $quantity = (int) ($item->quantity ?? 0);
        $unitPrice = (float) ($item->unit_price ?? 0.0);
        $item->amount = round($quantity * $unitPrice, 2);

        if (
            !empty($item->tax_rate_id)
            && (!$item->exists || $item->isDirty('tax_rate_id'))
        ) {
            $rate = self::resolveTaxRate((int) $item->tax_rate_id);

            if ($rate !== null) {
                $item->tax_rate = (float) $rate->rate;
            }
        }

        $taxRate = (float) ($item->tax_rate ?? 0.0);
        $item->tax_amount = round($item->amount * ($taxRate / 100), 2);
    }

    private static function resolveTaxRate(int $taxRateId): ?TaxRate
    {
        $scope = spl_object_id(app());

        $cached = self::$resolvedTaxRates[$scope][$taxRateId] ??= TaxRate::find($taxRateId) ?? false;

        return $cached === false ? null : $cached;
    }
}
