<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Events;

use Centrex\LaravelAccounting\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Payment $payment) {}
}
