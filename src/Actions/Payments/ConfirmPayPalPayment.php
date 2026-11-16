<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ConfirmPayPalPayment
{
    public function __construct(
        private readonly CapturePayPalOrder $capturePayPalOrder,
    ) {}

    public function handle(
        BookingPayment $payment,
        Model $customer,
        string $paypalOrderId,
    ): BookingPayment {
        if (trim($paypalOrderId) === '') {
            throw new PaymentGatewayException(
                'PayPal did not return an order reference.',
                'paypal_return_order_reference_missing',
            );
        }

        $verifiedPayment = DB::transaction(function () use ($payment, $customer, $paypalOrderId): BookingPayment {
            $currentPayment = BookingPayment::query()
                ->with('booking')
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $currentPayment->booking->user_id !== (int) $customer->getKey()) {
                throw new AuthorizationException('You are not allowed to confirm this booking payment.');
            }

            if ($currentPayment->provider !== PaymentProvider::PayPal) {
                throw new PaymentGatewayException(
                    'This payment does not belong to PayPal.',
                    'payment_provider_mismatch',
                );
            }

            if (! is_string($currentPayment->provider_reference)
                || ! hash_equals($currentPayment->provider_reference, $paypalOrderId)) {
                throw new PaymentGatewayException(
                    'The PayPal order returned by the browser does not match this booking payment.',
                    'paypal_return_order_mismatch',
                );
            }

            if (in_array($currentPayment->status, [
                PaymentStatus::Failed,
                PaymentStatus::Cancelled,
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                throw new PaymentGatewayException(
                    'This payment attempt is no longer eligible for browser capture.',
                    'paypal_payment_not_capturable',
                );
            }

            return $currentPayment;
        });

        return $this->capturePayPalOrder->handle($verifiedPayment, 'customer_return');
    }
}
