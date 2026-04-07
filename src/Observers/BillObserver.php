<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\Bill;

class BillObserver
{
    public function creating(Bill $bill): void
    {
        if (empty($bill->bill_number)) {
            $bill->bill_number = 'BILL-' . date('Ymd') . '-' . str_pad(
                (string) (Bill::whereDate('created_at', today())->count() + 1),
                4,
                '0',
                STR_PAD_LEFT,
            );
        }
    }
}
