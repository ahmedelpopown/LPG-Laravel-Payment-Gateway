<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\PayPal;

use Ahmed\LaravelPayment\Contracts\IdempotentRefundGateway;
use Ahmed\LaravelPayment\Data\CheckoutSessionData;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Data\RefundResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

final class PayPalGateway implements IdempotentRefundGateway
{
    public function __construct(
        private readonly PayPalClient $client,
    ) {}

    public function supportsCurrency(string $currency): bool
    {
        $supportedCurrencies = config('payments.paypal.supported_currencies', []);

        return is_array($supportedCurrencies)
            && in_array(strtoupper($currency), $supportedCurrencies, true);
    }

    /** Verify PayPal credentials/connectivity before starting a checkout. */
    public function checkAvailability(): void
    {
        $this->client->checkAvailability();
    }

    public function createCheckout(Model $payment): CheckoutSessionData
    {
        $this->assertPayPalPayment($payment);
        $this->assertPersistedPayment($payment);
        $this->assertSupportedCurrency($payment->currency);

        if ($payment->status !== PaymentStatus::Pending) {
            throw new PaymentGatewayException(
                'Only pending payments can start a PayPal checkout.',
                'payment_not_pending',
            );
        }

        $amount = $this->normalizeAmount((string) $payment->amount);
        $isBookingPayment = $payment->getTable() === 'booking_payments';

        if ($isBookingPayment && Route::has('payments.paypal.return') && Route::has('payments.paypal.cancel')) {
            $returnUrl = route('payments.paypal.return', ['payment' => $payment]);
            $cancelUrl = route('payments.paypal.cancel', ['payment' => $payment]);
        } else {
            $returnUrl = route('payments.core.return', ['provider' => 'paypal', 'payment' => $payment]);
            $cancelUrl = route('payments.core.cancel', ['provider' => 'paypal', 'payment' => $payment]);
        }

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $payment->getKey(),
                'custom_id' => $payment->idempotency_key,
                'invoice_id' => 'PAYMENT-'.$payment->getKey(),
                'amount' => [
                    'currency_code' => strtoupper($payment->currency),
                    'value' => $amount,
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'brand_name' => (string) config('payments.paypal.brand_name', config('app.name')),
                        'landing_page' => 'LOGIN',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
        ];

        $response = $this->client->createOrder(
            $payload,
            $this->requestId($payment, 'create'),
        );

        $orderId = $response['id'] ?? null;
        $approvalUrl = $this->approvalUrl($response['links'] ?? []);

        if (! is_string($orderId) || trim($orderId) === '' || $approvalUrl === null) {
            throw new PaymentGatewayException(
                'PayPal created an incomplete checkout order. No checkout URL was returned.',
                'paypal_checkout_response_incomplete',
            );
        }

        return new CheckoutSessionData(
            providerReference: $orderId,
            checkoutUrl: $approvalUrl,
            metadata: [
                'paypal_order_status' => $response['status'] ?? null,
                'paypal_create_response' => $response,
            ],
        );
    }

    public function capture(Model $payment): PaymentResultData
    {
        $this->assertPayPalPayment($payment);
        $this->assertPersistedPayment($payment);
        $this->assertSupportedCurrency($payment->currency);

        $orderId = $payment->provider_reference;

        if (! is_string($orderId) || trim($orderId) === '') {
            throw new PaymentGatewayException(
                'The payment does not have a PayPal order reference to capture.',
                'paypal_order_reference_missing',
            );
        }

        try {
            $response = $this->client->captureOrder(
                $orderId,
                $this->requestId($payment, 'capture'),
            );
        } catch (PaymentGatewayException $exception) {
            if (! $this->shouldRecoverCaptureOutcome($exception)) {
                throw $exception;
            }

            try {
                // A timeout does not prove the capture failed. Retrieve the
                // order before retrying so a completed capture is not treated
                // as a new payment attempt.
                $response = $this->client->showOrder($orderId);
            } catch (PaymentGatewayException $lookupException) {
                throw new PaymentGatewayException(
                    'PayPal could not confirm the result of the capture request. Keep this payment open and reconcile it before creating another payment.',
                    'paypal_capture_outcome_unknown',
                    $lookupException->httpStatus,
                    $exception,
                );
            }

            $recoveredCapture = $this->firstCapture($response);

            if (! is_array($recoveredCapture)) {
                throw $exception;
            }
        }

        $capture = $this->firstCapture($response);

        if (! is_array($capture)) {
            throw new PaymentGatewayException(
                'PayPal did not return capture details for this order.',
                'paypal_capture_details_missing',
            );
        }

        $capturedAmount = data_get($capture, 'amount.value');
        $capturedCurrency = data_get($capture, 'amount.currency_code');

        if (! is_string($capturedAmount) || ! is_string($capturedCurrency)) {
            throw new PaymentGatewayException(
                'PayPal returned incomplete amount details for this capture.',
                'paypal_capture_amount_missing',
            );
        }

        if ($this->normalizeAmount($capturedAmount) !== $this->normalizeAmount((string) $payment->amount)
            || strtoupper($capturedCurrency) !== strtoupper($payment->currency)) {
            throw new PaymentGatewayException(
                'The captured amount or currency does not match the saved payment. The payment was not marked as paid.',
                'paypal_capture_amount_mismatch',
            );
        }

        $captureStatus = strtoupper((string) ($capture['status'] ?? ''));
        $paymentStatus = match ($captureStatus) {
            'COMPLETED' => PaymentStatus::Paid,
            'PENDING' => PaymentStatus::Processing,
            'DECLINED', 'DENIED', 'FAILED' => PaymentStatus::Failed,
            'PARTIALLY_REFUNDED', 'REFUNDED' => throw new PaymentGatewayException(
                'PayPal reports that this capture has already been refunded. Reconcile the refund before finalizing this payment.',
                'paypal_capture_refund_state_requires_reconciliation',
            ),
            // Unknown future states must never be treated as a failed charge.
            default => PaymentStatus::Processing,
        };

        $breakdown = is_array($capture['seller_receivable_breakdown'] ?? null)
            ? $capture['seller_receivable_breakdown']
            : null;

        return new PaymentResultData(
            status: $paymentStatus,
            amount: $this->normalizeAmount($capturedAmount),
            currency: strtoupper($capturedCurrency),
            providerReference: $orderId,
            transactionId: is_string($capture['id'] ?? null) ? $capture['id'] : null,
            metadata: [
                'paypal_order_status' => $response['status'] ?? null,
                'paypal_capture_status' => $captureStatus,
                'paypal_status_details' => $capture['status_details'] ?? null,
                'paypal_seller_receivable_breakdown' => $breakdown,
                'paypal_gross_amount' => data_get($breakdown, 'gross_amount.value'),
                'paypal_fee_amount' => data_get($breakdown, 'paypal_fee.value'),
                'paypal_net_amount' => data_get($breakdown, 'net_amount.value'),
                'paypal_net_currency' => data_get($breakdown, 'net_amount.currency_code'),
            ],
        );
    }

    /** @param array<string, mixed> $response */
    private function firstCapture(array $response): ?array
    {
        $captures = data_get($response, 'purchase_units.0.payments.captures');

        if (! is_array($captures)) {
            return null;
        }

        foreach ($captures as $capture) {
            if (is_array($capture)) {
                return $capture;
            }
        }

        return null;
    }

    private function shouldRecoverCaptureOutcome(PaymentGatewayException $exception): bool
    {
        return $exception->providerCode === 'ORDER_ALREADY_CAPTURED'
            || in_array($exception->providerCode, [
                'paypal_connection_error',
                'paypal_capture_outcome_unknown',
            ], true)
            || in_array($exception->httpStatus, [408, 429, 500, 502, 503, 504], true);
    }

    public function refund(Model $payment, string $amount): RefundResultData
    {
        return $this->performRefund($payment, $amount, null);
    }

    public function refundWithIdempotency(Model $payment, string $amount, string $idempotencyKey): RefundResultData
    {
        return $this->performRefund($payment, $amount, $idempotencyKey);
    }

    private function performRefund(Model $payment, string $amount, ?string $idempotencyKey): RefundResultData
    {
        $this->assertPayPalPayment($payment);
        $this->assertPersistedPayment($payment);
        $this->assertSupportedCurrency($payment->currency);

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            throw new PaymentGatewayException(
                'Only a successfully captured payment can be refunded.',
                'payment_not_refundable',
            );
        }

        $captureId = $payment->provider_transaction_id;

        if (! is_string($captureId) || trim($captureId) === '') {
            throw new PaymentGatewayException(
                'A PayPal capture ID is required before a refund can be issued.',
                'paypal_capture_reference_missing',
            );
        }

        $refundAmount = $this->normalizeAmount($amount);
        $originalAmount = $this->normalizeAmount((string) $payment->amount);

        if ($this->amountInMinorUnits($refundAmount) <= 0
            || $this->amountInMinorUnits($refundAmount) > $this->amountInMinorUnits($originalAmount)) {
            throw new PaymentGatewayException(
                'The refund amount must be greater than zero and cannot exceed the original payment amount.',
                'paypal_refund_amount_invalid',
            );
        }

        $response = $this->client->refundCapture(
            $captureId,
            [
                'amount' => [
                    'value' => $refundAmount,
                    'currency_code' => strtoupper($payment->currency),
                ],
            ],
            $this->requestId($payment, 'refund-'.($idempotencyKey ?? $refundAmount)),
        );

        $refundId = $response['id'] ?? null;
        $refundStatus = strtoupper((string) ($response['status'] ?? ''));

        if (! is_string($refundId) || trim($refundId) === '') {
            throw new PaymentGatewayException(
                'PayPal did not return a refund reference.',
                'paypal_refund_reference_missing',
            );
        }

        $resultAmount = data_get($response, 'amount.value', $refundAmount);
        $resultCurrency = data_get($response, 'amount.currency_code', strtoupper($payment->currency));

        if (! is_string($resultAmount) || ! is_string($resultCurrency)
            || strtoupper($resultCurrency) !== strtoupper($payment->currency)
            || $this->normalizeAmount($resultAmount) !== $refundAmount) {
            throw new PaymentGatewayException(
                'The refund amount or currency returned by PayPal does not match the requested refund.',
                'paypal_refund_amount_mismatch',
            );
        }

        $resultStatus = match ($refundStatus) {
            'COMPLETED' => $this->amountInMinorUnits($refundAmount) === $this->amountInMinorUnits($originalAmount)
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded,
            'PENDING' => PaymentStatus::Processing,
            default => throw new PaymentGatewayException(
                'PayPal did not complete the refund.',
                'paypal_refund_not_completed',
            ),
        };

        return new RefundResultData(
            providerRefundId: $refundId,
            amount: $refundAmount,
            currency: strtoupper($resultCurrency),
            status: $resultStatus,
            metadata: [
                'paypal_refund_status' => $refundStatus,
                'paypal_status_details' => $response['status_details'] ?? null,
            ],
        );
    }

    private function assertPayPalPayment(Model $payment): void
    {
        if ($payment->provider !== PaymentProvider::PayPal) {
            throw new PaymentGatewayException(
                'This payment record does not belong to PayPal.',
                'payment_provider_mismatch',
            );
        }
    }

    private function assertPersistedPayment(Model $payment): void
    {
        if (! $payment->exists || $payment->getKey() === null) {
            throw new PaymentGatewayException(
                'The payment must be saved before contacting PayPal.',
                'payment_not_persisted',
            );
        }

        if (trim((string) $payment->idempotency_key) === '') {
            throw new PaymentGatewayException(
                'The payment is missing its idempotency key.',
                'payment_idempotency_key_missing',
            );
        }
    }

    private function assertSupportedCurrency(string $currency): void
    {
        $currency = strtoupper($currency);

        if (! $this->supportsCurrency($currency)) {
            throw new PaymentGatewayException(
                sprintf(
                    'PayPal Orders API does not currently support [%s]. Do not send this payment until a supported-currency conversion is implemented.',
                    $currency,
                ),
                'paypal_unsupported_currency',
            );
        }
    }

    /** @param mixed $links */
    private function approvalUrl(mixed $links): ?string
    {
        if (! is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (! is_array($link) || ! in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) {
                continue;
            }

            $url = $link['href'] ?? null;
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($url) && is_string($host)
                && (strtolower($host) === 'paypal.com' || str_ends_with(strtolower($host), '.paypal.com'))
                && in_array(parse_url($url, PHP_URL_SCHEME), ['https'], true)) {
                return $url;
            }
        }

        return null;
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new PaymentGatewayException(
                'The payment amount must be a positive decimal with no more than two decimal places.',
                'paypal_amount_invalid',
            );
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $normalized = $matches[1].'.'.$fraction;

        if (((int) $matches[1] * 100) + (int) $fraction <= 0) {
            throw new PaymentGatewayException(
                'PayPal payments and refunds must be greater than zero.',
                'paypal_amount_must_be_positive',
            );
        }

        return $normalized;
    }

    private function amountInMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $this->normalizeAmount($amount), 2), 2, '00');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function requestId(Model $payment, string $operation): string
    {
        $hash = substr(hash('sha256', $operation.':'.$payment->idempotency_key), 0, 32);

        return substr($hash, 0, 8).'-'
            .substr($hash, 8, 4).'-4'
            .substr($hash, 13, 3).'-a'
            .substr($hash, 17, 3).'-'
            .substr($hash, 20, 12);
    }
}
