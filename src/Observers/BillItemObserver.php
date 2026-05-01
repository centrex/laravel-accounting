<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Observers;

use Centrex\Accounting\Concerns\ComputesLineItemAmounts;
use Centrex\Accounting\Models\BillItem;

class BillItemObserver
{
    use ComputesLineItemAmounts;

    public function saving(BillItem $item): void
    {
        $this->computeAmounts($item);
    }
}
