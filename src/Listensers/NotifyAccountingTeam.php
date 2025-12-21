<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Listeners;

use Centrex\LaravelAccounting\Events\PaymentRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyAccountingTeam implements ShouldQueue
{
    public function handle(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        Log::debug($payment);
    }
}
