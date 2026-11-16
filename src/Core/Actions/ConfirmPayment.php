<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Actions;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;

final class ConfirmPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly FinalizePayment $finalizer,
    ) {}

    public function handle(Payment $payment): Payment
    {
        $result = $this->gateways->driver($payment->provider)->capture($payment);

        return $this->finalizer->handle($payment, $result);
    }
}
