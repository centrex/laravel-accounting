<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

trait ComputesLineItemAmounts
{
    protected function computeAmounts(object $item): void
    {
        $quantity = (int) ($item->quantity ?? 0);
        $unitPrice = (float) ($item->unit_price ?? 0.0);
        $item->amount = round($quantity * $unitPrice, 2);

        $taxRate = (float) ($item->tax_rate ?? 0.0);
        $item->tax_amount = round($item->amount * ($taxRate / 100), 2);
    }
}
