<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\InvoiceItem;

class InvoiceItemObserver
{
    public function saving(InvoiceItem $item): void
    {
        // ensure unit_price & quantity set
        $quantity = (int) ($item->quantity ?? 0);
        $unitPrice = (float) ($item->unit_price ?? 0.0);
        $item->amount = round($quantity * $unitPrice, 2);

        $taxRate = (float) ($item->tax_rate ?? 0.0);
        $item->tax_amount = round($item->amount * ($taxRate / 100), 2);
    }
}
