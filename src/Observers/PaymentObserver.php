<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Observers;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Events\PaymentStatusChanged;

final class PaymentObserver
{
    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        event(new PaymentStatusChanged($payment));
    }
}