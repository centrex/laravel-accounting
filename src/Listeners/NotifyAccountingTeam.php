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

        // Structured log — safe to log references and IDs, not full model data
        Log::info('Payment recorded', [
            'payment_number' => $payment->payment_number,
            'amount'         => $payment->amount,
            'method'         => $payment->payment_method,
            'payable_type'   => class_basename($payment->payable_type),
            'payable_id'     => $payment->payable_id,
            'payment_date'   => $payment->payment_date,
        ]);

        // Hook point: extend this listener per your application.
        // Examples:
        //   Mail::to(config('accounting.team_email'))->queue(new PaymentReceivedMail($payment));
        //   Notification::route('slack', config('accounting.slack_webhook'))->notify(...);
    }
}
