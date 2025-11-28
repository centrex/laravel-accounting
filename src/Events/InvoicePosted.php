<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Events;

use Centrex\LaravelAccounting\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoicePosted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Invoice $invoice) {}
}
