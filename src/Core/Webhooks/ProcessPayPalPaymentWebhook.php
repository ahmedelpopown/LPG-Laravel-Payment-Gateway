<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Webhooks;

use Ahmed\LaravelPayment\Core\Actions\FinalizePayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Data\PaymentResultData;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessPayPalPaymentWebhook
{
    private const CAPTURE_EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED' => PaymentStatus::Paid,
        'PAYMENT.CAPTURE.PENDING' => PaymentStatus::Processing,
        'PAYMENT.CAPTURE.DENIED' => PaymentStatus::Failed,
    ];

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly FinalizePayment $finalizer,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): string
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['event_type'] ?? null;
        if (! is_string($eventId) || trim($eventId) === ''
            || ! is_string($eventType) || trim($eventType) === '') {
            throw new PaymentGatewayException('PayPal webhook event is missing its ID or type.', 'paypal_webhook_event_invalid');
        }

        $record = $this->recordEvent($event, $eventId, $eventType);
        if ($record === null) {
            $existing = PaymentWebhookEvent::query()
                ->where('provider', PaymentProvider::PayPal->value)
                ->where('event_id', $eventId)
                ->first();

            // Only terminal events are safe to acknowledge as duplicates. An event
            // still marked processing/received may be in-flight or awaiting retry.
            if (! $existing || ! in_array($existing->status, ['processed', 'ignored', 'rejected'], true)) {
                throw new PaymentGatewayException(
                    'PayPal webhook event is already being processed or is awaiting retry.',
                    'paypal_webhook_event_in_progress',
                    503,
                );
            }

            return 'duplicate';
        }

        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            $resource = $event['resource'] ?? null;
            $orderId = is_array($resource) ? ($resource['id'] ?? null) : null;
            if (! is_array($resource) || ! is_string($orderId) || trim($orderId) === '') {
                $this->markEvent($record, 'rejected', 'paypal_approved_order_payload_invalid');
                return 'rejected';
            }

            $payment = $this->findPayment($orderId);
            if (! $payment) {
                $this->markEvent($record, 'received', 'paypal_webhook_payment_not_found');
                throw new PaymentGatewayException('No local payment matches this approved PayPal order.', 'paypal_webhook_payment_not_found');
            }

            try {
                $result = $this->gateways->driver(PaymentProvider::PayPal)->capture($payment);
                $this->finalizer->handle($payment, $result);
                $this->markEvent($record, 'processed', null, $payment->getKey());
                return 'processed';
            } catch (Throwable $exception) {
                $errorCode = $exception instanceof PaymentGatewayException
                    ? (string) $exception->providerCode
                    : 'paypal_webhook_processing_failed';
                $permanent = preg_match('/(invalid|mismatch|missing|unsupported)/i', $errorCode) === 1;
                $this->markEvent($record, $permanent ? 'rejected' : 'received', $errorCode, $payment->getKey());
                if ($exception instanceof PaymentGatewayException) {
                    throw $exception;
                }
                throw new PaymentGatewayException('PayPal webhook processing is temporarily unavailable.', 'paypal_webhook_processing_failed', null, $exception);
            }
        }

        if (! array_key_exists($eventType, self::CAPTURE_EVENTS)) {
            $this->markEvent($record, 'ignored');
            return 'ignored';
        }

        $resource = $event['resource'] ?? null;
        $orderId = is_array($resource) ? data_get($resource, 'supplementary_data.related_ids.order_id') : null;
        $amount = is_array($resource) ? data_get($resource, 'amount.value') : null;
        $currency = is_array($resource) ? data_get($resource, 'amount.currency_code') : null;
        $captureId = is_array($resource) ? ($resource['id'] ?? null) : null;

        if (! is_array($resource) || ! is_string($orderId) || trim($orderId) === '') {
            $this->markEvent($record, 'rejected', 'paypal_webhook_order_reference_missing');
            return 'rejected';
        }

        $payment = $this->findPayment($orderId);
        if (! $payment) {
            $this->markEvent($record, 'received', 'paypal_webhook_payment_not_found');
            throw new PaymentGatewayException('No local payment matches this PayPal capture event.', 'paypal_webhook_payment_not_found');
        }

        if (! is_string($amount) || ! preg_match('/^\d{1,14}(?:\.\d{1,2})?$/D', $amount)
            || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', strtoupper($currency))) {
            $this->markEvent($record, 'rejected', 'paypal_webhook_amount_invalid', $payment->getKey());
            return 'rejected';
        }

        $status = self::CAPTURE_EVENTS[$eventType];
        if ($status === PaymentStatus::Paid && (! is_string($captureId) || trim($captureId) === '')) {
            $this->markEvent($record, 'rejected', 'paypal_webhook_capture_reference_missing', $payment->getKey());
            return 'rejected';
        }

        try {
            $result = new PaymentResultData(
                status: $status,
                amount: $amount,
                currency: strtoupper($currency),
                providerReference: $orderId,
                transactionId: is_string($captureId) ? $captureId : null,
                metadata: [
                    'paypal_webhook_event_id' => $eventId,
                    'paypal_webhook_event_type' => $eventType,
                    'paypal_capture_status' => data_get($resource, 'status'),
                ],
            );

            $this->finalizer->handle($payment, $result);
            $this->markEvent($record, 'processed', null, $payment->getKey());
            return 'processed';
        } catch (Throwable $exception) {
            $code = $exception instanceof PaymentGatewayException
                ? (string) $exception->providerCode
                : 'paypal_webhook_processing_failed';
            $permanent = preg_match('/(invalid|mismatch|missing|unsupported)/i', $code) === 1;
            $this->markEvent($record, $permanent ? 'rejected' : 'received', $code, $payment->getKey());
            if ($exception instanceof PaymentGatewayException) {
                throw $exception;
            }
            throw new PaymentGatewayException('PayPal webhook processing is temporarily unavailable.', 'paypal_webhook_processing_failed', null, $exception);
        }
    }

    private function findPayment(string $orderId): ?Payment
    {
        return Payment::query()
            ->where('provider', PaymentProvider::PayPal->value)
            ->where('provider_reference', $orderId)
            ->first();
    }

    /** @param array<string, mixed> $event */
    private function recordEvent(array $event, string $eventId, string $eventType): ?PaymentWebhookEvent
    {
        try {
            return DB::transaction(function () use ($event, $eventId, $eventType): ?PaymentWebhookEvent {
                $existing = PaymentWebhookEvent::query()
                    ->where('provider', PaymentProvider::PayPal->value)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->claimReceivedEvent($existing);
                }

                return PaymentWebhookEvent::query()->create([
                    'provider' => PaymentProvider::PayPal->value,
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
                    ->where('provider', PaymentProvider::PayPal->value)
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

    private function markEvent(
        PaymentWebhookEvent $event,
        string $status,
        ?string $errorCode = null,
        ?int $paymentId = null,
    ): void {
        $event->forceFill([
            'payment_id' => $paymentId ?? $event->payment_id,
            'status' => $status,
            'error_code' => $errorCode,
            'processed_at' => $status === 'received' ? null : now(),
        ])->save();
    }
}
