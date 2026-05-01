<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Observers;

use Centrex\Accounting\Concerns\ComputesLineItemAmounts;
use Centrex\Accounting\Models\InvoiceItem;

class InvoiceItemObserver
{
    use ComputesLineItemAmounts;

    public function saving(InvoiceItem $item): void
    {
        $this->computeAmounts($item);
    }
}
