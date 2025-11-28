<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Listeners;

use Centrex\LaravelAccounting\Events\InvoicePosted;
use Illuminate\Contracts\Queue\ShouldQueue;

class SyncCustomerOutstanding implements ShouldQueue
{
    public function handle(InvoicePosted $event): void
    {
        $invoice = $event->invoice;

        if ($customer = $invoice->customer) {
            // Example: dispatch a job or refresh cached outstanding amounts
            // $customer->refreshOutstandingCache();
            // Keep minimal here — extend per your app
        }
    }
}
