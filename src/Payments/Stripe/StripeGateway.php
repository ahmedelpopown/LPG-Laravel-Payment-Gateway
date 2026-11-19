<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\Stripe;

use Ahmed\LaravelPayment\Contracts\CancellableCheckoutGateway;
use Ahmed\LaravelPayment\Contracts\IdempotentRefundGateway;
use Ahmed\LaravelPayment\Core\Models\Payment as CorePayment;
use Ahmed\LaravelPayment\Data\CheckoutSessionData;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Data\RefundResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Database\Eloquent\Model;

final class StripeGateway implements IdempotentRefundGateway, CancellableCheckoutGateway
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    public function supportsCurrency(string $currency): bool
    {
        $supportedCurrencies = config('payments.stripe.supported_currencies', []);

        return is_array($supportedCurrencies)
            && in_array(strtoupper($currency), $supportedCurrencies, true);
    }


public function createCheckout(Model $payment): CheckoutSessionData
{
    $this->assertStripePayment($payment);
    $this->assertPersistedPayment($payment);
    $this->assertSupportedCurrency((string) $payment->currency);

    if ($payment->status !== PaymentStatus::Pending) {
        throw new PaymentGatewayException(
            'Only pending payments can start a Stripe Checkout Session.',
            'payment_not_pending',
        );
    }

    $amountInMinorUnits = $this->toMinorUnits((string) $payment->amount);
    $currency = strtolower((string) $payment->currency);
    $paymentId = (string) $payment->getKey();

    $successUrl = route('payments.core.return', [
        'provider' => 'stripe',
        'payment' => $payment,
    ]).'?session_id={CHECKOUT_SESSION_ID}';

    $cancelUrl = route('payments.core.cancel', [
        'provider' => 'stripe',
        'payment' => $payment,
    ]);

    $metadata = [
        'payment_id' => $paymentId,
        'payment_model' => class_basename($payment),
    ];

    $session = $this->client->createCheckoutSession([
        'mode' => 'payment',

        // payment_method_types removed.
        // Stripe manages available methods through Dashboard.

        'client_reference_id' => $paymentId,

        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,

        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $amountInMinorUnits,
                'product_data' => [
                    'name' => (string) config('payments.stripe.product_name', 'Online payment').' #'.$paymentId,
                ],
            ],
        ]],

        'metadata' => $metadata,

        'payment_intent_data' => [
            'metadata' => $metadata,
        ],
    ], $this->idempotencyKey($payment, 'checkout'));

    $sessionId = $session['id'] ?? null;
    $checkoutUrl = $session['url'] ?? null;

    if (
        ! is_string($sessionId) || trim($sessionId) === ''
        || ! is_string($checkoutUrl) || trim($checkoutUrl) === ''
    ) {
        throw new PaymentGatewayException(
            'Stripe created an incomplete Checkout Session. No checkout URL was returned.',
            'stripe_checkout_response_incomplete',
        );
    }

    return new CheckoutSessionData(
        providerReference: $sessionId,
        checkoutUrl: $checkoutUrl,
        metadata: [
            'stripe_session_status' => $session['status'] ?? null,
            'stripe_payment_status' => $session['payment_status'] ?? null,
        ],
    );
}


    /**
     * Stripe Checkout uses automatic capture for this integration. This method
     * retrieves the Session from Stripe and verifies it before recording payment.
     */
    public function capture(Model $payment): PaymentResultData
    {
        $this->assertStripePayment($payment);
        $this->assertPersistedPayment($payment);
        $this->assertSupportedCurrency((string) $payment->currency);

        $sessionId = $payment->provider_reference;

        if (! is_string($sessionId) || trim($sessionId) === '') {
            throw new PaymentGatewayException(
                'The payment does not have a Stripe Checkout Session reference.',
                'stripe_session_reference_missing',
            );
        }

        $session = $this->client->retrieveCheckoutSession($sessionId);
        $this->assertSessionMatchesPayment($session, $payment);

        $paymentStatus = strtolower((string) ($session['payment_status'] ?? ''));
        $sessionStatus = strtolower((string) ($session['status'] ?? ''));
        $status = match (true) {
            $paymentStatus === 'paid' => PaymentStatus::Paid,
            $sessionStatus === 'expired' => PaymentStatus::Failed,
            default => PaymentStatus::Processing,
        };

        $paymentIntentId = $this->extractId($session['payment_intent'] ?? null);

        if ($status === PaymentStatus::Paid && $paymentIntentId === null) {
            throw new PaymentGatewayException(
                'Stripe marked the Checkout Session as paid but did not return a PaymentIntent reference.',
                'stripe_payment_intent_reference_missing',
            );
        }

        return new PaymentResultData(
            status: $status,
            amount: $this->fromMinorUnits((int) $session['amount_total']),
            currency: strtoupper((string) $session['currency']),
            providerReference: $sessionId,
            transactionId: $paymentIntentId,
            metadata: [
                'stripe_session_status' => $sessionStatus,
                'stripe_payment_status' => $paymentStatus,
                'stripe_session_id' => $sessionId,
            ],
        );
    }

    /**
     * Cancel an unpaid Stripe Checkout Session.
     *
     * The local payment is only cancelled after Stripe confirms that the
     * Checkout Session is expired and remains unpaid. An ambiguous network
     * result is deliberately propagated so the application keeps the attempt
     * open until it can be reconciled.
     */
    public function cancelCheckout(Model $payment): PaymentResultData
    {
        $this->assertStripePayment($payment);
        $this->assertPersistedPayment($payment);
        $this->assertSupportedCurrency((string) $payment->currency);

        $sessionId = $payment->provider_reference;
        if (! is_string($sessionId) || trim($sessionId) === '') {
            throw new PaymentGatewayException(
                'The payment does not have a Stripe Checkout Session reference.',
                'stripe_session_reference_missing',
            );
        }

        $session = $this->client->retrieveCheckoutSession($sessionId);
        $this->assertSessionMatchesPayment($session, $payment);

        $settledResult = $this->cancellationResultForKnownSessionState($session);
        if ($settledResult !== null) {
            return $settledResult;
        }

        $sessionStatus = strtolower((string) ($session['status'] ?? ''));
        $paymentStatus = strtolower((string) ($session['payment_status'] ?? ''));

        if ($sessionStatus !== 'open' || $paymentStatus !== 'unpaid') {
            throw new PaymentGatewayException(
                'Stripe has not confirmed that this Checkout Session can be cancelled. Keep the current payment attempt open and retry synchronization.',
                'stripe_checkout_cancellation_not_confirmed',
            );
        }

        try {
            $expiredSession = $this->client->expireCheckoutSession($sessionId);
            $this->assertSessionMatchesPayment($expiredSession, $payment);
        } catch (PaymentGatewayException $exception) {
            // The expiry request may have reached Stripe even when the response
            // was lost. Read the session again before deciding its local state.
            try {
                $latestSession = $this->client->retrieveCheckoutSession($sessionId);
                $this->assertSessionMatchesPayment($latestSession, $payment);
            } catch (PaymentGatewayException $lookupException) {
                throw new PaymentGatewayException(
                    'Stripe could not confirm whether the Checkout Session was cancelled. The payment remains open; do not start another provider attempt yet.',
                    'stripe_checkout_cancellation_outcome_unknown',
                    $lookupException->httpStatus,
                    $exception,
                );
            }

            $recoveredResult = $this->cancellationResultForKnownSessionState($latestSession);
            if ($recoveredResult !== null) {
                return $recoveredResult;
            }

            // It is still open/unpaid or in an unexpected state. Do not mark
            // the local payment cancelled when Stripe has not confirmed it.
            throw $exception;
        }

        $result = $this->cancellationResultForKnownSessionState($expiredSession);
        if ($result === null || $result->status !== PaymentStatus::Cancelled) {
            throw new PaymentGatewayException(
                'Stripe did not confirm that the Checkout Session expired while unpaid. The payment attempt remains open.',
                'stripe_checkout_cancellation_not_confirmed',
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $session */
    private function cancellationResultForKnownSessionState(array $session): ?PaymentResultData
    {
        $sessionStatus = strtolower((string) ($session['status'] ?? ''));
        $providerPaymentStatus = strtolower((string) ($session['payment_status'] ?? ''));
        $amount = $this->fromMinorUnits((int) ($session['amount_total'] ?? 0));
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        $sessionId = (string) ($session['id'] ?? '');
        $paymentIntentId = $this->extractId($session['payment_intent'] ?? null);

        if ($providerPaymentStatus === 'paid') {
            if ($paymentIntentId === null) {
                throw new PaymentGatewayException(
                    'Stripe reports that this Checkout Session is paid but did not return its PaymentIntent reference.',
                    'stripe_payment_intent_reference_missing',
                );
            }

            return new PaymentResultData(
                status: PaymentStatus::Paid,
                amount: $amount,
                currency: $currency,
                providerReference: $sessionId,
                transactionId: $paymentIntentId,
                metadata: [
                    'stripe_session_status' => $sessionStatus,
                    'stripe_payment_status' => $providerPaymentStatus,
                    'cancellation_attempt_result' => 'already_paid',
                ],
            );
        }

        if ($sessionStatus === 'expired' && $providerPaymentStatus === 'unpaid') {
            return new PaymentResultData(
                status: PaymentStatus::Cancelled,
                amount: $amount,
                currency: $currency,
                providerReference: $sessionId,
                metadata: [
                    'stripe_session_status' => $sessionStatus,
                    'stripe_payment_status' => $providerPaymentStatus,
                    'cancelled_at_provider' => now()->toIso8601String(),
                ],
            );
        }

        if ($sessionStatus === 'complete') {
            // Some asynchronous payment methods can complete the Checkout
            // Session before the final payment result is known. Do not unlock
            // another provider while Stripe still reports unpaid.
            return new PaymentResultData(
                status: PaymentStatus::Processing,
                amount: $amount,
                currency: $currency,
                providerReference: $sessionId,
                transactionId: $paymentIntentId,
                metadata: [
                    'stripe_session_status' => $sessionStatus,
                    'stripe_payment_status' => $providerPaymentStatus,
                    'cancellation_attempt_result' => 'payment_processing',
                ],
            );
        }

        return null;
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
        $this->assertStripePayment($payment);
        $this->assertPersistedPayment($payment);
        $this->assertSupportedCurrency((string) $payment->currency);

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            throw new PaymentGatewayException(
                'Only a captured Stripe payment can be refunded.',
                'payment_not_refundable',
            );
        }

        $paymentIntentId = $payment->provider_transaction_id;

        if (! is_string($paymentIntentId) || trim($paymentIntentId) === '') {
            throw new PaymentGatewayException(
                'A Stripe PaymentIntent ID is required before a refund can be issued.',
                'stripe_payment_intent_reference_missing',
            );
        }

        $refundAmount = $this->normalizeAmount($amount);
        $refundMinorUnits = $this->toMinorUnits($refundAmount);

        if ($refundMinorUnits <= 0) {
            throw new PaymentGatewayException(
                'The refund amount must be greater than zero.',
                'stripe_refund_amount_invalid',
            );
        }

        // Ask Stripe for the current captured/refunded totals so cumulative
        // partial refunds cannot exceed the original charge.
        $intent = $this->client->retrievePaymentIntent($paymentIntentId, [
            'expand' => ['latest_charge'],
        ]);
        $charge = $intent['latest_charge'] ?? null;
        $intentCurrency = strtoupper((string) ($intent['currency'] ?? ''));
        $intentAmount = (int) ($intent['amount'] ?? 0);
        $receivedMinorUnits = (int) ($intent['amount_received'] ?? 0);

        if ($intentCurrency !== strtoupper((string) $payment->currency)
            || $intentAmount !== $this->toMinorUnits((string) $payment->amount)
            || $receivedMinorUnits !== $this->toMinorUnits((string) $payment->amount)) {
            throw new PaymentGatewayException(
                'The Stripe PaymentIntent amount or currency does not match the saved payment.',
                'stripe_refund_payment_intent_mismatch',
            );
        }

        $alreadyRefundedMinorUnits = is_array($charge)
            ? (int) ($charge['amount_refunded'] ?? 0)
            : 0;
        $remainingMinorUnits = max(0, $receivedMinorUnits - $alreadyRefundedMinorUnits);

        if ($refundMinorUnits > $remainingMinorUnits) {
            throw new PaymentGatewayException(
                'The refund amount exceeds the remaining refundable Stripe balance.',
                'stripe_refund_amount_exceeds_remaining',
            );
        }

        $providerIdempotencyKey = $idempotencyKey !== null
            ? 'payment-refund-'.substr(hash('sha256', (string) $payment->idempotency_key.'|'.$idempotencyKey), 0, 48)
            : $this->idempotencyKey($payment, 'refund-'.$refundAmount);

        $refundPayload = [
            'payment_intent' => $paymentIntentId,
            'amount' => $refundMinorUnits,
        ];

        // Store a correlation key on Stripe so a refund.created webhook that
        // races the local response can still attach to the already-reserved row.
        if ($payment instanceof CorePayment && $idempotencyKey !== null) {
            $refundPayload['metadata'] = [
                'payment_id' => (string) $payment->getKey(),
                'payment_model' => 'Payment',
                'payment_refund_idempotency_key' => $idempotencyKey,
            ];
        }

        $refund = $this->client->createRefund($refundPayload, $providerIdempotencyKey);

        $refundId = $refund['id'] ?? null;
        $refundStatus = strtolower((string) ($refund['status'] ?? ''));
        $returnedAmount = (int) ($refund['amount'] ?? 0);
        $returnedCurrency = strtoupper((string) ($refund['currency'] ?? ''));

        if (! is_string($refundId) || trim($refundId) === '') {
            throw new PaymentGatewayException(
                'Stripe did not return a refund reference.',
                'stripe_refund_reference_missing',
            );
        }

        if ($returnedAmount !== $refundMinorUnits || $returnedCurrency !== strtoupper((string) $payment->currency)) {
            throw new PaymentGatewayException(
                'The refund amount or currency returned by Stripe does not match the requested refund.',
                'stripe_refund_amount_mismatch',
            );
        }

        $resultStatus = match ($refundStatus) {
            'succeeded' => ($alreadyRefundedMinorUnits + $refundMinorUnits) >= $receivedMinorUnits
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded,
            'pending', 'requires_action' => PaymentStatus::Processing,
            default => throw new PaymentGatewayException(
                'Stripe did not complete the refund.',
                'stripe_refund_not_completed',
            ),
        };

        return new RefundResultData(
            providerRefundId: $refundId,
            amount: $this->fromMinorUnits($returnedAmount),
            currency: $returnedCurrency,
            status: $resultStatus,
            metadata: [
                'stripe_refund_status' => $refundStatus,
                'stripe_payment_intent_id' => $paymentIntentId,
            ],
        );
    }

    /** Validate a Stripe webhook Session against the saved Core payment. */
    public function assertWebhookSessionMatchesPayment(array $session, Model $payment): void
    {
        $this->assertStripePayment($payment);
        $this->assertSessionMatchesPayment($session, $payment);
    }

    /**
     * Build a failed result only after the webhook Session has been matched to
     * the exact local payment, amount, and currency.
     *
     * @param array<string, mixed> $session
     */
    public function failedWebhookResult(
        array $session,
        Model $payment,
        string $eventId,
        string $eventType,
    ): PaymentResultData {
        $this->assertWebhookSessionMatchesPayment($session, $payment);

        return new PaymentResultData(
            status: PaymentStatus::Failed,
            amount: $this->fromMinorUnits((int) $session['amount_total']),
            currency: strtoupper((string) $session['currency']),
            providerReference: (string) $session['id'],
            metadata: [
                'stripe_webhook_event_id' => $eventId,
                'stripe_webhook_event_type' => $eventType,
                'stripe_payment_status' => $session['payment_status'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $session */
    private function assertSessionMatchesPayment(array $session, Model $payment): void
    {
        $sessionId = $session['id'] ?? null;
        $clientReferenceId = $session['client_reference_id'] ?? null;
        $metadataPaymentId = data_get($session, 'metadata.payment_id');
        $amountTotal = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        if (! is_string($sessionId) || ! is_string($payment->provider_reference)
            || ! hash_equals($payment->provider_reference, $sessionId)) {
            throw new PaymentGatewayException(
                'The Stripe Checkout Session does not match the saved payment.',
                'stripe_session_reference_mismatch',
            );
        }

        if ((string) $payment->getKey() !== (string) $clientReferenceId
            || (string) $payment->getKey() !== (string) $metadataPaymentId) {
            throw new PaymentGatewayException(
                'The Stripe Checkout Session metadata does not identify this payment.',
                'stripe_session_metadata_mismatch',
            );
        }

        if (! is_int($amountTotal) || ! is_string($currency)
            || $amountTotal !== $this->toMinorUnits((string) $payment->amount)
            || strtoupper($currency) !== strtoupper((string) $payment->currency)) {
            throw new PaymentGatewayException(
                'The Stripe Checkout Session amount or currency does not match the saved payment.',
                'stripe_session_amount_mismatch',
            );
        }
    }

    private function assertStripePayment(Model $payment): void
    {
        if ($payment->provider !== PaymentProvider::Stripe) {
            throw new PaymentGatewayException(
                'This payment does not belong to Stripe.',
                'payment_provider_mismatch',
            );
        }
    }

    private function assertPersistedPayment(Model $payment): void
    {
        if (! $payment->exists || $payment->getKey() === null) {
            throw new PaymentGatewayException(
                'A persisted payment is required before using Stripe.',
                'payment_not_persisted',
            );
        }

        if (! is_string($payment->idempotency_key) || trim($payment->idempotency_key) === '') {
            throw new PaymentGatewayException(
                'The payment is missing its idempotency key.',
                'payment_idempotency_key_missing',
            );
        }
    }

    private function assertSupportedCurrency(string $currency): void
    {
        if (! $this->supportsCurrency($currency)) {
            throw new PaymentGatewayException(
                'Stripe does not support the configured currency for this integration.',
                'stripe_currency_not_supported',
            );
        }
    }

    private function normalizeAmount(string $amount): string
    {
        return $this->fromMinorUnits($this->toMinorUnits($amount));
    }

    private function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', trim($amount), $matches)) {
            throw new PaymentGatewayException(
                'The Stripe amount must be a non-negative decimal with up to two decimal places.',
                'stripe_amount_invalid',
            );
        }

        $minorUnits = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');

        // Stripe supports up to eight amount digits for most two-decimal currencies.
        if ($minorUnits > 99_999_999) {
            throw new PaymentGatewayException(
                'The Stripe amount exceeds the maximum supported charge for this integration.',
                'stripe_amount_too_large',
            );
        }

        return $minorUnits;
    }

    private function fromMinorUnits(int $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }

    private function extractId(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null)) {
            return $value['id'];
        }

        return null;
    }

    private function idempotencyKey(Model $payment, string $operation): string
    {
        return 'payment-'.$operation.'-'.substr(hash('sha256', (string) $payment->idempotency_key.'-'.$operation), 0, 48);
    }
}
