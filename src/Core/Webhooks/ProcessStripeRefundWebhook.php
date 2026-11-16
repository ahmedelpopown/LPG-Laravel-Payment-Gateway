<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Webhooks;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Core\Models\PaymentRefund;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Enums\RefundStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use Ahmed\LaravelPayment\Payments\Stripe\StripeClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Synchronize Stripe Refund objects into the generic Payment Core ledger.
 *
 * Refund events are deliberately processed separately from Checkout events:
 * a Stripe refund can be created by this package, from the Stripe Dashboard,
 * or by another authorized integration.
 */
final class ProcessStripeRefundWebhook
{
    private const REFUND_EVENTS = [
        'refund.created',
        'refund.updated',
        'refund.failed',
        // Stripe emits this for selected payment methods. refund.updated is
        // the general event, but accepting this alias costs nothing.
        'charge.refund.updated',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    /**
     * @param array<string, mixed> $event
     * @return 'processed'|'duplicate'|'ignored'|'rejected'
     */
    public function handle(array $event): string
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;

        if (! is_string($eventId) || trim($eventId) === ''
            || ! is_string($eventType) || trim($eventType) === '') {
            throw new PaymentGatewayException(
                'Stripe refund webhook event is missing its ID or type.',
                'stripe_refund_webhook_event_invalid',
            );
        }

        $record = $this->recordEvent($event, $eventId, $eventType);
        if ($record === null) {
            return 'duplicate';
        }

        $payment = null;

        try {
            if ($eventType === 'charge.refunded') {
                $payment = $this->processChargeRefunded($event, $record);
                if (! $payment) {
                    return 'ignored';
                }
            } elseif (in_array($eventType, self::REFUND_EVENTS, true)) {
                $refund = data_get($event, 'data.object');

                if (! is_array($refund)) {
                    $this->markEvent($record, 'rejected', 'stripe_refund_payload_invalid');
                    return 'rejected';
                }

                $payment = $this->findPaymentForRefund($refund);
                if (! $payment) {
                    // The Stripe account can contain charges that do not belong
                    // to this Laravel application. Do not retry those forever.
                    $this->markEvent($record, 'ignored');
                    return 'ignored';
                }

                $this->syncRefundObject(
                    payment: $payment,
                    refund: $refund,
                    eventId: $eventId,
                    eventType: $eventType,
                    eventCreated: $this->eventCreated($event),
                );
            } else {
                $this->markEvent($record, 'ignored');
                return 'ignored';
            }

            $record->forceFill([
                'payment_id' => $payment?->getKey(),
                'status' => 'processed',
                'error_code' => null,
                'processed_at' => now(),
            ])->save();

            return 'processed';
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof PaymentGatewayException
                ? (string) $exception->providerCode
                : 'stripe_refund_webhook_processing_failed';

            $permanent = preg_match('/(invalid|mismatch|missing|unsupported|exceeds)/i', $errorCode) === 1;

            $record->forceFill([
                'payment_id' => $payment?->getKey() ?? $record->payment_id,
                'status' => $permanent ? 'rejected' : 'received',
                'error_code' => $errorCode,
                'processed_at' => $permanent ? now() : null,
            ])->save();

            if ($exception instanceof PaymentGatewayException) {
                throw $exception;
            }

            throw new PaymentGatewayException(
                'Stripe refund webhook processing is temporarily unavailable.',
                'stripe_refund_webhook_processing_failed',
                null,
                $exception,
            );
        }
    }

    /**
     * Handle Stripe's aggregate Charge event by syncing every Refund currently
     * associated with that Charge. The embedded collection is limited, so the
     * full list endpoint is used whenever Stripe reports more pages.
     *
     * @param array<string, mixed> $event
     */
    private function processChargeRefunded(array $event, PaymentWebhookEvent $record): ?Payment
    {
        $charge = data_get($event, 'data.object');
        $chargeId = is_array($charge) ? ($charge['id'] ?? null) : null;

        if (! is_array($charge) || ! is_string($chargeId) || trim($chargeId) === '') {
            throw new PaymentGatewayException(
                'Stripe charge.refunded event is missing a valid Charge object.',
                'stripe_charge_refunded_payload_invalid',
            );
        }

        $embeddedRefunds = data_get($charge, 'refunds.data', []);
        $hasMore = (bool) data_get($charge, 'refunds.has_more', false);

        if (! is_array($embeddedRefunds) || $hasMore || $embeddedRefunds === []) {
            $embeddedRefunds = $this->stripe->listRefundsForCharge($chargeId);
        }

        if ($embeddedRefunds === [] && (int) ($charge['amount_refunded'] ?? 0) > 0) {
            throw new PaymentGatewayException(
                'Stripe reports a refunded Charge but returned no Refund objects.',
                'stripe_charge_refunds_missing',
            );
        }

        $paymentIntentId = $this->extractId($charge['payment_intent'] ?? null);
        if ($paymentIntentId === null) {
            foreach ($embeddedRefunds as $candidate) {
                if (is_array($candidate)) {
                    $paymentIntentId = $this->extractId($candidate['payment_intent'] ?? null);
                    if ($paymentIntentId !== null) {
                        break;
                    }
                }
            }
        }

        $payment = $paymentIntentId !== null
            ? $this->findPaymentByPaymentIntent($paymentIntentId)
            : null;

        if (! $payment) {
            foreach ($embeddedRefunds as $candidate) {
                if (is_array($candidate) && ($payment = $this->findPaymentForRefund($candidate))) {
                    break;
                }
            }
        }

        if (! $payment) {
            $this->markEvent($record, 'ignored');
            return null;
        }

        foreach ($embeddedRefunds as $refund) {
            if (! is_array($refund)) {
                throw new PaymentGatewayException(
                    'Stripe returned an invalid Refund object inside charge.refunded.',
                    'stripe_refund_payload_invalid',
                );
            }

            $this->syncRefundObject(
                payment: $payment,
                refund: $refund,
                eventId: (string) ($event['id'] ?? ''),
                eventType: 'charge.refunded',
                eventCreated: $this->eventCreated($event),
                fallbackPaymentIntentId: $paymentIntentId,
            );
        }

        return $payment;
    }

    /**
     * @param array<string, mixed> $refund
     */
    private function findPaymentForRefund(array $refund): ?Payment
    {
        $paymentIntentId = $this->extractId($refund['payment_intent'] ?? null);
        $metadata = is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
        $paymentModel = $metadata['payment_model'] ?? null;
        $metadataPaymentId = $metadata['payment_id'] ?? null;

        if ($paymentIntentId !== null) {
            $payment = $this->findPaymentByPaymentIntent($paymentIntentId);
            if ($payment) {
                // If Stripe metadata is present, it must agree with the
                // PaymentIntent-to-Payment mapping. Do not silently trust one
                // correlation source when the other contradicts it.
                if ($paymentModel === 'Payment'
                    && is_scalar($metadataPaymentId)
                    && trim((string) $metadataPaymentId) !== ''
                    && (string) $payment->getKey() !== (string) $metadataPaymentId) {
                    throw new PaymentGatewayException(
                        'Stripe Refund metadata points to a different local Payment.',
                        'stripe_refund_payment_mismatch',
                    );
                }

                return $payment;
            }
        }

        // Refunds created by this package carry a Stripe metadata correlation
        // to the local Core Payment and refund idempotency key.
        if ($paymentModel !== 'Payment'
            || ! is_scalar($metadataPaymentId)
            || trim((string) $metadataPaymentId) === '') {
            return null;
        }

        $payment = Payment::query()
            ->whereKey((string) $metadataPaymentId)
            ->where('provider', PaymentProvider::Stripe->value)
            ->first();

        if ($payment && $paymentIntentId !== null
            && $payment->provider_transaction_id !== null
            && ! hash_equals((string) $payment->provider_transaction_id, $paymentIntentId)) {
            throw new PaymentGatewayException(
                'Stripe Refund metadata points to a different local PaymentIntent.',
                'stripe_refund_payment_mismatch',
            );
        }

        return $payment;
    }

    private function findPaymentByPaymentIntent(string $paymentIntentId): ?Payment
    {
        $payment = Payment::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->where('provider_transaction_id', $paymentIntentId)
            ->first();

        if ($payment) {
            return $payment;
        }

        // A Refund can be reported before the Checkout success webhook is
        // delivered. Recover the Core Payment through the metadata that this
        // package attached to the PaymentIntent at Checkout creation time.
        // A 404 means this Stripe account has no such PaymentIntent, so the
        // event is not owned by this package and should be ignored, not retried.
        try {
            $intent = $this->stripe->retrievePaymentIntent($paymentIntentId);
        } catch (PaymentGatewayException $exception) {
            if ($exception->httpStatus === 404) {
                return null;
            }

            throw $exception;
        }

        $metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
        $paymentModel = $metadata['payment_model'] ?? null;
        $paymentId = $metadata['payment_id'] ?? null;

        if ($paymentModel !== 'Payment' || ! is_scalar($paymentId) || trim((string) $paymentId) === '') {
            return null;
        }

        $payment = Payment::query()
            ->whereKey((string) $paymentId)
            ->where('provider', PaymentProvider::Stripe->value)
            ->first();

        if (! $payment) {
            return null;
        }

        $intentCurrency = strtoupper((string) ($intent['currency'] ?? ''));
        $intentAmount = $intent['amount'] ?? null;
        $receivedAmount = $intent['amount_received'] ?? null;
        $expectedMinor = $this->toMinor((string) $payment->amount);

        if ($intentCurrency !== strtoupper((string) $payment->currency)
            || ! is_int($intentAmount)
            || $intentAmount !== $expectedMinor
            || ! is_int($receivedAmount)
            || $receivedAmount !== $expectedMinor
            || strtolower((string) ($intent['status'] ?? '')) !== 'succeeded') {
            throw new PaymentGatewayException(
                'Stripe PaymentIntent metadata matched a local Payment, but its amount, currency, or captured state did not.',
                'stripe_refund_payment_intent_mismatch',
            );
        }

        return DB::transaction(function () use ($payment, $paymentIntentId): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->provider_transaction_id !== null
                && ! hash_equals((string) $locked->provider_transaction_id, $paymentIntentId)) {
                throw new PaymentGatewayException(
                    'The local Payment is already linked to a different Stripe PaymentIntent.',
                    'stripe_refund_payment_mismatch',
                );
            }

            $locked->forceFill([
                'provider_transaction_id' => $paymentIntentId,
                'status' => in_array($locked->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)
                    ? PaymentStatus::Paid
                    : $locked->status,
                'paid_at' => $locked->paid_at ?? now(),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * Upsert one Stripe Refund and recalculate the parent Payment from the
     * completed refund ledger. The Payment row lock serializes different
     * Stripe events referring to the same refund/payment.
     *
     * @param array<string, mixed> $refund
     */
    private function syncRefundObject(
        Payment $payment,
        array $refund,
        string $eventId,
        string $eventType,
        int $eventCreated,
        ?string $fallbackPaymentIntentId = null,
    ): PaymentRefund {
        $refundId = $refund['id'] ?? null;
        $amountMinor = $refund['amount'] ?? null;
        $currency = strtoupper((string) ($refund['currency'] ?? ''));
        $paymentIntentId = $this->extractId($refund['payment_intent'] ?? null)
            ?? $fallbackPaymentIntentId;

        if (! is_string($refundId) || trim($refundId) === ''
            || ! is_int($amountMinor) || $amountMinor <= 0
            || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new PaymentGatewayException(
                'Stripe Refund payload is missing a valid ID, amount, or currency.',
                'stripe_refund_payload_invalid',
            );
        }

        if ($payment->provider !== PaymentProvider::Stripe
            || strtoupper((string) $payment->currency) !== $currency) {
            throw new PaymentGatewayException(
                'Stripe Refund provider or currency does not match the local Payment.',
                'stripe_refund_payment_mismatch',
            );
        }

        if ($paymentIntentId !== null
            && $payment->provider_transaction_id !== null
            && ! hash_equals((string) $payment->provider_transaction_id, $paymentIntentId)) {
            throw new PaymentGatewayException(
                'Stripe Refund PaymentIntent does not match the local Payment.',
                'stripe_refund_payment_mismatch',
            );
        }

        $amount = $this->minorToMajor($amountMinor);
        $providerStatus = strtolower((string) ($refund['status'] ?? ''));
        $incomingStatus = $this->mapRefundStatus($eventType, $providerStatus);
        $metadata = is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
        $localIdempotencyKey = $metadata['payment_refund_idempotency_key'] ?? null;
        $reason = is_string($refund['reason'] ?? null) ? $refund['reason'] : null;
        $failureReason = is_string($refund['failure_reason'] ?? null) ? $refund['failure_reason'] : null;
        $chargeId = $this->extractId($refund['charge'] ?? null);

        return DB::transaction(function () use (
            $payment,
            $refundId,
            $amount,
            $currency,
            $providerStatus,
            $incomingStatus,
            $localIdempotencyKey,
            $reason,
            $failureReason,
            $chargeId,
            $paymentIntentId,
            $eventId,
            $eventType,
            $eventCreated,
        ): PaymentRefund {
            $lockedPayment = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $localRefund = PaymentRefund::query()
                ->where('provider', PaymentProvider::Stripe->value)
                ->where('provider_refund_id', $refundId)
                ->lockForUpdate()
                ->first();

            if ($localRefund && (string) $localRefund->payment_id !== (string) $lockedPayment->getKey()) {
                throw new PaymentGatewayException(
                    'Stripe Refund ID is already linked to a different local Payment.',
                    'stripe_refund_payment_mismatch',
                );
            }

            if (! $localRefund && is_string($localIdempotencyKey) && trim($localIdempotencyKey) !== '') {
                $localRefund = PaymentRefund::query()
                    ->where('payment_id', $lockedPayment->getKey())
                    ->where('idempotency_key', $localIdempotencyKey)
                    ->lockForUpdate()
                    ->first();
            }

            $idempotencyKey = is_string($localIdempotencyKey) && trim($localIdempotencyKey) !== ''
                ? $localIdempotencyKey
                : 'stripe-external-refund-'.substr(hash('sha256', $refundId), 0, 48);

            if ($localRefund && $localRefund->provider_refund_id !== null
                && ! hash_equals((string) $localRefund->provider_refund_id, $refundId)) {
                throw new PaymentGatewayException(
                    'Stripe Refund metadata conflicts with the existing local Refund reference.',
                    'stripe_refund_reference_mismatch',
                );
            }

            if ($localRefund && $this->toMinor((string) $localRefund->amount) !== $this->toMinor($amount)) {
                throw new PaymentGatewayException(
                    'Stripe Refund amount does not match the existing local Refund.',
                    'stripe_refund_amount_mismatch',
                );
            }

            if ($localRefund && strtoupper((string) $localRefund->currency) !== $currency) {
                throw new PaymentGatewayException(
                    'Stripe Refund currency does not match the existing local Refund.',
                    'stripe_refund_currency_mismatch',
                );
            }

            if (! $localRefund) {
                $localRefund = PaymentRefund::query()->create([
                    'payment_id' => $lockedPayment->getKey(),
                    'provider' => PaymentProvider::Stripe->value,
                    'provider_refund_id' => $refundId,
                    'idempotency_key' => $idempotencyKey,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $incomingStatus,
                    'reason' => $reason,
                    'failure_message' => $incomingStatus === RefundStatus::Failed
                        ? ($failureReason ?? 'Stripe reported that this refund failed or was canceled.')
                        : null,
                    'metadata' => [],
                    'processed_at' => in_array($incomingStatus, [RefundStatus::Completed, RefundStatus::Failed], true) ? now() : null,
                ]);
            }

            $existingMetadata = $localRefund->metadata ?? [];
            $lastEventCreated = (int) ($existingMetadata['stripe_last_event_created'] ?? 0);
            $isOlderEvent = $lastEventCreated > $eventCreated;
            $currentStatus = $localRefund->status;
            $isTerminalCurrentStatus = in_array(
                $currentStatus,
                [RefundStatus::Completed, RefundStatus::Failed],
                true,
            );

            // Completed and failed are terminal provider outcomes. Never let
            // a later-delivered event (or an event with the same second-level
            // Stripe timestamp) flip one terminal result into another. A new
            // retry must be represented by a new Stripe Refund object/ID.
            $conflictsWithTerminalStatus = $isTerminalCurrentStatus
                && $incomingStatus !== $currentStatus;

            $applyIncomingStatus = ! $isOlderEvent && ! $conflictsWithTerminalStatus;
            $nextStatus = $applyIncomingStatus ? $incomingStatus : $currentStatus;

            // Only advance the provider snapshot when the incoming event is
            // allowed to advance the refund state. The event itself remains
            // separately recorded in payment_webhook_events for auditability.
            $nextMetadata = $applyIncomingStatus
                ? array_merge($existingMetadata, [
                    'stripe_refund_status' => $providerStatus !== ''
                        ? $providerStatus
                        : ($existingMetadata['stripe_refund_status'] ?? null),
                    'stripe_payment_intent_id' => $paymentIntentId
                        ?? ($existingMetadata['stripe_payment_intent_id'] ?? null),
                    'stripe_charge_id' => $chargeId
                        ?? ($existingMetadata['stripe_charge_id'] ?? null),
                    'stripe_last_event_id' => $eventId,
                    'stripe_last_event_type' => $eventType,
                    'stripe_last_event_created' => max($lastEventCreated, $eventCreated),
                ])
                : $existingMetadata;

            $localRefund->forceFill([
                'provider_refund_id' => $refundId,
                'status' => $nextStatus,
                'reason' => $localRefund->reason ?? $reason,
                'failure_message' => $nextStatus === RefundStatus::Failed
                    ? ($failureReason ?? $localRefund->failure_message ?? 'Stripe reported that this refund failed or was canceled.')
                    : null,
                'metadata' => $nextMetadata,
                'processed_at' => in_array($nextStatus, [RefundStatus::Completed, RefundStatus::Failed], true)
                    ? ($localRefund->processed_at ?? now())
                    : null,
            ])->save();

            $this->recalculatePaymentRefundTotals($lockedPayment);

            return $localRefund->fresh();
        });
    }

    private function recalculatePaymentRefundTotals(Payment $payment): void
    {
        $refunds = PaymentRefund::query()
            ->where('payment_id', $payment->getKey())
            ->get(['amount', 'status']);

        $completedMinor = 0;
        $reservedMinor = 0;

        foreach ($refunds as $refund) {
            $amountMinor = $this->toMinor((string) $refund->amount);
            $status = $refund->status instanceof RefundStatus
                ? $refund->status
                : RefundStatus::from((string) $refund->status);

            if ($status === RefundStatus::Completed) {
                $completedMinor += $amountMinor;
            }

            if (in_array($status, [RefundStatus::Pending, RefundStatus::Processing, RefundStatus::Completed], true)) {
                $reservedMinor += $amountMinor;
            }
        }

        $paymentMinor = $this->toMinor((string) $payment->amount);
        if ($reservedMinor > $paymentMinor || $completedMinor > $paymentMinor) {
            throw new PaymentGatewayException(
                'The Stripe Refund ledger exceeds the original local Payment amount.',
                'stripe_refund_total_exceeds_payment',
            );
        }

        $newStatus = match (true) {
            $completedMinor >= $paymentMinor => PaymentStatus::Refunded,
            $completedMinor > 0 => PaymentStatus::PartiallyRefunded,
            in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true) => PaymentStatus::Paid,
            default => $payment->status,
        };

        $payment->forceFill([
            'refunded_amount' => $this->minorToMajor($completedMinor),
            'status' => $newStatus,
            'refunded_at' => $newStatus === PaymentStatus::Refunded
                ? ($payment->refunded_at ?? now())
                : null,
        ])->save();
    }

    private function mapRefundStatus(string $eventType, string $providerStatus): RefundStatus
    {
        if ($eventType === 'refund.failed' || in_array($providerStatus, ['failed', 'canceled'], true)) {
            return RefundStatus::Failed;
        }

        return match ($providerStatus) {
            'succeeded' => RefundStatus::Completed,
            'pending' => RefundStatus::Pending,
            'requires_action' => RefundStatus::Processing,
            default => RefundStatus::Processing,
        };
    }

    private function eventCreated(array $event): int
    {
        return is_numeric($event['created'] ?? null)
            ? (int) $event['created']
            : now()->timestamp;
    }

    private function minorToMajor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function toMinor(string $amount): int
    {
        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,2}))?$/D', trim($amount), $matches)) {
            throw new PaymentGatewayException('Invalid monetary amount in the local Refund ledger.', 'stripe_refund_amount_invalid');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function extractId(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null) && trim($value['id']) !== '') {
            return $value['id'];
        }

        return null;
    }

    /** @param array<string, mixed> $event */
    private function recordEvent(array $event, string $eventId, string $eventType): ?PaymentWebhookEvent
    {
        try {
            return DB::transaction(function () use ($event, $eventId, $eventType): ?PaymentWebhookEvent {
                $existing = PaymentWebhookEvent::query()
                    ->where('provider', PaymentProvider::Stripe->value)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->claimReceivedEvent($existing);
                }

                return PaymentWebhookEvent::query()->create([
                    'provider' => PaymentProvider::Stripe->value,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'status' => 'processing',
                    'payload' => $event,
                ]);
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)
                && ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw $exception;
            }

            return DB::transaction(function () use ($eventId): ?PaymentWebhookEvent {
                $existing = PaymentWebhookEvent::query()
                    ->where('provider', PaymentProvider::Stripe->value)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                return $existing ? $this->claimReceivedEvent($existing) : null;
            });
        }
    }

    private function claimReceivedEvent(PaymentWebhookEvent $event): ?PaymentWebhookEvent
    {
        $staleProcessing = $event->status === 'processing'
            && $event->updated_at !== null
            && $event->updated_at->lte(now()->subMinutes(5));

        if ($event->status !== 'received' && ! $staleProcessing) {
            return null;
        }

        $event->forceFill([
            'status' => 'processing',
            'error_code' => null,
            'processed_at' => null,
        ])->save();

        return $event->fresh();
    }

    private function markEvent(PaymentWebhookEvent $event, string $status, ?string $errorCode = null): void
    {
        $event->forceFill([
            'status' => $status,
            'error_code' => $errorCode,
            'processed_at' => in_array($status, ['received', 'processing'], true) ? null : now(),
        ])->save();
    }
}
