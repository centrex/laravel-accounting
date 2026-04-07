<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\Invoice;

class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        if (empty($invoice->invoice_number)) {
            $invoice->invoice_number = 'INV-' . date('Ymd') . '-' . str_pad(
                Invoice::whereDate('created_at', today())->count() + 1,
                4,
                '0',
                STR_PAD_LEFT,
            );
        }
    }
}
