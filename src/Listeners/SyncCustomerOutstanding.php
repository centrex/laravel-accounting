<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Listeners;

use Centrex\LaravelAccounting\Events\InvoicePosted;
use Centrex\LaravelAccounting\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCustomerOutstanding implements ShouldQueue
{
    public function handle(InvoicePosted $event): void
    {
        $invoice = $event->invoice;

        if (! $customer = $invoice->customer) {
            return;
        }

        try {
            // Recompute outstanding balance from all non-settled invoices
            $outstanding = Invoice::where('customer_id', $customer->id)
                ->whereNotIn('status', ['settled', 'draft'])
                ->selectRaw('SUM(total - paid_amount) as balance')
                ->value('balance') ?? 0.0;

            // Update customer cache column if it exists, silently skip otherwise
            if (in_array('outstanding_balance', $customer->getFillable(), true)) {
                $customer->update(['outstanding_balance' => $outstanding]);
            }
        } catch (\Throwable $e) {
            // Log but don't fail — this is a background sync, not a critical path
            Log::warning('SyncCustomerOutstanding failed', [
                'customer_id' => $customer->id,
                'invoice_id'  => $invoice->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
