<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Events;

use Centrex\Accounting\Models\Bill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillPosted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Bill $bill) {}
}
