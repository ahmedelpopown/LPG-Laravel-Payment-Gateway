<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Events;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class PaymentStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Payment $payment,
    ) {}
}