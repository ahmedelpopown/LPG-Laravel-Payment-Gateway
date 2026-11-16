<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessPayPalWebhook
{
    private const CAPTURE_EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED' => PaymentStatus::Paid,
        'PAYMENT.CAPTURE.PENDING' => PaymentStatus::Processing,
        'PAYMENT.CAPTURE.DENIED' => PaymentStatus::Failed,
    ];

    public function __construct(
        private readonly FinalizeBookingPayment $finalizeBookingPayment,
        private readonly CapturePayPalOrder $capturePayPalOrder,
    ) {}

    /**
     * Process a signature-verified PayPal event.
     *
     * Returns one of: processed, ignored, rejected, duplicate.
     *
     * @param array<string, mixed> $event
     */
    public function handle(array $event): string
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['event_type'] ?? null;

        if (! is_string($eventId) || trim($eventId) === ''
            || ! is_string($eventType) || trim($eventType) === '') {
            throw new PaymentGatewayException(
                'PayPal webhook event is missing its ID or event type.',
                'paypal_webhook_event_invalid',
            );
        }

        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            return $this->processApprovedOrder($event, $eventId);
        }

        return $this->processCaptureEvent($event, $eventId, $eventType);
    }

    /**
     * Capture approved orders from the webhook as well as from the browser return.
     * Network calls intentionally happen outside the database transaction.
     *
     * @param array<string, mixed> $event
     */
    private function processApprovedOrder(array $event, string $eventId): string
    {
        $webhookEvent = $this->recordReceivedEvent($event, $eventId, 'CHECKOUT.ORDER.APPROVED');

        if ($webhookEvent === null) {
            return 'duplicate';
        }

        $resource = $event['resource'] ?? null;
        $orderId = is_array($resource) ? ($resource['id'] ?? null) : null;

        if (! is_array($resource)
            || ! is_string($orderId)
            || trim($orderId) === ''
            || strtoupper((string) ($resource['status'] ?? '')) !== 'APPROVED') {
            $this->markWebhookEvent($webhookEvent, 'rejected', 'paypal_approved_order_payload_invalid');

            return 'rejected';
        }

        $payment = BookingPayment::query()
            ->where('provider', PaymentProvider::PayPal->value)
            ->where('provider_reference', $orderId)
            ->first();

        if (! $payment) {
            // Keep the event in received state so PayPal can retry after the
            // checkout action finishes saving the Order ID.
            throw new PaymentGatewayException(
                'The booking payment for this approved PayPal order has not been found yet.',
                'paypal_webhook_payment_not_found',
            );
        }

        try {
            $capturedPayment = $this->capturePayPalOrder->handle($payment, 'paypal_webhook');
        } catch (PaymentGatewayException $exception) {
            if ($this->isRetryableProviderFailure($exception)) {
                $webhookEvent->forceFill([
                    'error_code' => $exception->providerCode,
                ])->save();

                throw $exception;
            }

            $this->markWebhookEvent(
                $webhookEvent,
                'rejected',
                $exception->providerCode ?? 'paypal_approved_order_capture_failed',
                $payment->getKey(),
            );

            return 'rejected';
        } catch (Throwable $exception) {
            $webhookEvent->forceFill([
                'error_code' => 'paypal_approved_order_capture_unexpected_error',
            ])->save();

            throw $exception;
        }

        $this->markWebhookEvent(
            $webhookEvent,
            'processed',
            null,
            $capturedPayment->getKey(),
        );

        return 'processed';
    }

    /**
     * Process capture state events that already contain a Capture resource.
     *
     * @param array<string, mixed> $event
     */
    private function processCaptureEvent(array $event, string $eventId, string $eventType): string
    {
        try {
            return DB::transaction(function () use ($event, $eventId, $eventType): string {
                $webhookEvent = PaymentWebhookEvent::query()
                    ->where('provider', PaymentProvider::PayPal->value)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($webhookEvent && $webhookEvent->status !== 'received') {
                    return 'duplicate';
                }

                if (! $webhookEvent) {
                    $webhookEvent = PaymentWebhookEvent::query()->create([
                        'provider' => PaymentProvider::PayPal->value,
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                        'status' => 'received',
                        'payload' => $event,
                    ]);
                }

                if (! array_key_exists($eventType, self::CAPTURE_EVENTS)) {
                    $webhookEvent->forceFill([
                        'status' => 'ignored',
                        'processed_at' => now(),
                    ])->save();

                    return 'ignored';
                }

                $resource = $event['resource'] ?? null;
                $orderId = is_array($resource)
                    ? data_get($resource, 'supplementary_data.related_ids.order_id')
                    : null;

                if (! is_array($resource) || ! is_string($orderId) || trim($orderId) === '') {
                    $webhookEvent->forceFill([
                        'status' => 'rejected',
                        'error_code' => 'paypal_webhook_order_reference_missing',
                        'processed_at' => now(),
                    ])->save();

                    return 'rejected';
                }

                $payment = BookingPayment::query()
                    ->where('provider', PaymentProvider::PayPal->value)
                    ->where('provider_reference', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    // Roll back a newly-created event row so PayPal can retry.
                    throw new PaymentGatewayException(
                        'The booking payment for this PayPal event has not been found yet.',
                        'paypal_webhook_payment_not_found',
                    );
                }

                $amount = data_get($resource, 'amount.value');
                $currency = data_get($resource, 'amount.currency_code');
                $captureId = $resource['id'] ?? null;

                if (! is_string($amount)
                    || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)
                    || ! is_string($currency)
                    || ! preg_match('/^[A-Z]{3}$/D', strtoupper($currency))) {
                    $this->rejectEvent($webhookEvent, $payment, 'paypal_webhook_amount_invalid');

                    return 'rejected';
                }

                $resultStatus = self::CAPTURE_EVENTS[$eventType];

                if ($resultStatus === PaymentStatus::Paid
                    && (! is_string($captureId) || trim($captureId) === '')) {
                    $this->rejectEvent($webhookEvent, $payment, 'paypal_webhook_capture_reference_missing');

                    return 'rejected';
                }

                try {
                    $result = new PaymentResultData(
                        status: $resultStatus,
                        amount: $amount,
                        currency: strtoupper($currency),
                        providerReference: $orderId,
                        transactionId: is_string($captureId) ? $captureId : null,
                        metadata: [
                            'paypal_webhook_event_id' => $eventId,
                            'paypal_webhook_event_type' => $eventType,
                            'paypal_capture_status' => $resource['status'] ?? null,
                        ],
                    );

                    $finalizedPayment = $this->finalizeBookingPayment->handle($payment, $result);
                } catch (PaymentGatewayException $exception) {
                    $this->rejectEvent(
                        $webhookEvent,
                        $payment,
                        $exception->providerCode ?? 'paypal_webhook_payment_result_rejected',
                    );

                    return 'rejected';
                }

                $webhookEvent->forceFill([
                    'booking_payment_id' => $finalizedPayment->getKey(),
                    'status' => 'processed',
                    'processed_at' => now(),
                ])->save();

                return 'processed';
            });
        } catch (QueryException $exception) {
            // The unique provider/event key is the final concurrency guard.
            $isUniqueViolation = in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($exception->getMessage()), 'unique');

            if ($isUniqueViolation && PaymentWebhookEvent::query()
                ->where('provider', PaymentProvider::PayPal->value)
                ->where('event_id', $eventId)
                ->exists()) {
                return 'duplicate';
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $event */
    private function recordReceivedEvent(array $event, string $eventId, string $eventType): ?PaymentWebhookEvent
    {
        try {
            return DB::transaction(function () use ($event, $eventId, $eventType): ?PaymentWebhookEvent {
                $existingEvent = PaymentWebhookEvent::query()
                    ->where('provider', PaymentProvider::PayPal->value)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($existingEvent) {
                    return $existingEvent->status === 'received' ? $existingEvent : null;
                }

                return PaymentWebhookEvent::query()->create([
                    'provider' => PaymentProvider::PayPal->value,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'status' => 'received',
                    'payload' => $event,
                ]);
            });
        } catch (QueryException $exception) {
            $isUniqueViolation = in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($exception->getMessage()), 'unique');

            if ($isUniqueViolation) {
                return PaymentWebhookEvent::query()
                    ->where('provider', PaymentProvider::PayPal->value)
                    ->where('event_id', $eventId)
                    ->where('status', 'received')
                    ->first();
            }

            throw $exception;
        }
    }

    private function markWebhookEvent(
        PaymentWebhookEvent $event,
        string $status,
        ?string $errorCode = null,
        ?int $paymentId = null,
    ): void {
        $event->forceFill([
            'booking_payment_id' => $paymentId ?? $event->booking_payment_id,
            'status' => $status,
            'error_code' => $errorCode,
            'processed_at' => $status === 'received' ? null : now(),
        ])->save();
    }

    private function rejectEvent(
        PaymentWebhookEvent $event,
        BookingPayment $payment,
        string $errorCode,
    ): void {
        $event->forceFill([
            'booking_payment_id' => $payment->getKey(),
            'status' => 'rejected',
            'error_code' => $errorCode,
            'processed_at' => now(),
        ])->save();
    }

    private function isRetryableProviderFailure(PaymentGatewayException $exception): bool
    {
        return $exception->providerCode === 'paypal_connection_error'
            || $exception->httpStatus === 408
            || $exception->httpStatus === 429
            || ($exception->httpStatus !== null && $exception->httpStatus >= 500);
    }
}
