<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Listeners;

use Centrex\Accounting\Events\{InvoicePosted, PaymentRecorded};
use Centrex\Accounting\Models\{Customer, Invoice};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncCustomerOutstanding implements ShouldQueue
{
    public function handle(InvoicePosted $event): void
    {
        $customer = $event->invoice->customer;

        if ($customer) {
            $this->resync($customer, $event->invoice->id);
        }
    }

    public function handlePayment(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        if ($payment->payable_type !== Invoice::class) {
            return;
        }

        $invoice = Invoice::find($payment->payable_id);
        $customer = $invoice?->customer;

        if ($customer) {
            $this->resync($customer, $invoice->id);
        }
    }

    private function resync(Customer $customer, int $invoiceId): void
    {
        try {
            // Recompute outstanding balance from all non-settled, non-void invoices.
            // toBase() drops down to the query builder so this aggregate never hydrates
            // an Invoice model missing the `id` column (which trips strict-attribute mode).
            $outstanding = Invoice::where('customer_id', $customer->id)
                ->whereNotIn('status', ['settled', 'draft', 'void'])
                ->toBase()
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
                'invoice_id'  => $invoiceId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
