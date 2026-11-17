<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Contracts;

use Ahmed\LaravelPayment\Data\PaymentResultData;
use Illuminate\Database\Eloquent\Model;

/** Provider gateways that can remotely close an unpaid checkout session. */
interface CancellableCheckoutGateway
{
    public function cancelCheckout(Model $payment): PaymentResultData;
}
