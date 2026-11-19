<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Tests\Fakes\Payments;

use Ahmed\LaravelPayment\Contracts\IdempotentRefundGateway;
use Ahmed\LaravelPayment\Data\CheckoutSessionData;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Data\RefundResultData;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Deterministic gateway for automated tests only. Never register this class
 * in the production payments configuration.
 */
final class FakePaymentGateway implements IdempotentRefundGateway
{
    public function supportsCurrency(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/D', strtoupper($currency)) === 1;
    }

    public function createCheckout(Model $payment): CheckoutSessionData
    {
        $paymentId = $payment->getKey() ?? 'unsaved';

        return new CheckoutSessionData(
            providerReference: "fake-order-{$paymentId}",
            checkoutUrl: "https://payments.test/checkout/{$paymentId}",
            metadata: ['environment' => 'testing'],
        );
    }

    public function capture(Model $payment): PaymentResultData
    {
        return new PaymentResultData(
            status: PaymentStatus::Paid,
            amount: (string) $payment->amount,
            currency: (string) $payment->currency,
            providerReference: $payment->provider_reference,
            transactionId: 'fake-transaction-'.$payment->getKey(),
            metadata: ['environment' => 'testing'],
        );
    }

    public function refund(Model $payment, string $amount): RefundResultData
    {
        $status = self::toMinorUnits($amount) >= self::toMinorUnits((string) $payment->amount)
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        return new RefundResultData(
            providerRefundId: 'fake-refund-'.$payment->getKey(),
            amount: $amount,
            currency: (string) $payment->currency,
            status: $status,
            metadata: ['environment' => 'testing'],
        );
    }

    public function refundWithIdempotency(Model $payment, string $amount, string $idempotencyKey): RefundResultData
    {
        return $this->refund($payment, $amount);
    }

    private static function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
