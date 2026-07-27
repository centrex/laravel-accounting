<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Listeners;

use Centrex\Accounting\Events\{BillPosted, PaymentRecorded};
use Centrex\Accounting\Models\{Bill, Vendor};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncVendorOutstanding implements ShouldQueue
{
    public function handle(BillPosted $event): void
    {
        $vendor = $event->bill->vendor;

        if ($vendor) {
            $this->resync($vendor, $event->bill->id);
        }
    }

    public function handlePayment(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        if ($payment->payable_type !== Bill::class) {
            return;
        }

        $bill = Bill::find($payment->payable_id);
        $vendor = $bill?->vendor;

        if ($vendor) {
            $this->resync($vendor, $bill->id);
        }
    }

    private function resync(Vendor $vendor, int $billId): void
    {
        try {
            // Recompute outstanding balance from all non-settled, non-void bills
            $outstanding = Bill::where('vendor_id', $vendor->id)
                ->whereNotIn('status', ['settled', 'draft', 'void'])
                ->selectRaw('SUM(total - paid_amount) as balance')
                ->value('balance') ?? 0.0;

            // Update vendor cache column if it exists, silently skip otherwise
            if (in_array('outstanding_balance', $vendor->getFillable(), true)) {
                $vendor->update(['outstanding_balance' => $outstanding]);
            }
        } catch (\Throwable $e) {
            // Log but don't fail — this is a background sync, not a critical path
            Log::warning('SyncVendorOutstanding failed', [
                'vendor_id' => $vendor->id,
                'bill_id'   => $billId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
