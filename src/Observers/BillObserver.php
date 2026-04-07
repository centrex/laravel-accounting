<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\Bill;
use Illuminate\Support\Str;

class BillObserver
{
    public function creating(Bill $bill): void
    {
        if (empty($bill->bill_number)) {
            $bill->bill_number = sprintf(
                'BILL-%s-%s',
                now()->format('Ymd-His'),
                Str::lower(Str::random(4)),
            );
        }
    }
}
