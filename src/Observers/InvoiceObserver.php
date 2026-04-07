<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\Invoice;
use Illuminate\Support\Str;

class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        if (empty($invoice->invoice_number)) {
            $invoice->invoice_number = sprintf(
                'INV-%s-%s',
                now()->format('Ymd-His'),
                Str::lower(Str::random(4)),
            );
        }
    }
}
