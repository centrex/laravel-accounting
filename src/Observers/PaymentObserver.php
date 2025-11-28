<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\Payment;
use Illuminate\Support\Str;

class PaymentObserver
{
    public function creating(Payment $payment): void
    {
        if (empty($payment->payment_number)) {
            $payment->payment_number = $this->generateFallbackNumber('PMT');
        }
    }

    private function generateFallbackNumber(string $prefix = 'PMT'): string
    {
        return sprintf(
            '%s-%s-%s',
            strtoupper($prefix),
            now()->format('Ymd-His'),
            Str::lower(Str::random(6)),
        );
    }
}
