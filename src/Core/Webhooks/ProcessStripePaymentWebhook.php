<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Webhooks;

use Ahmed\LaravelPayment\Core\Actions\FinalizePayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Ahmed\LaravelPayment\Payments\Stripe\StripeGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessStripePaymentWebhook
{
    private const SUCCESS_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
    ];

    private const FAILURE_EVENTS = ['checkout.session.async_payment_failed'];
    private const EXPIRED_EVENTS = ['checkout.session.expired'];

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly FinalizePayment $finalizer,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): string
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;

        if (! is_string($eventId) || trim($eventId) === ''
            || ! is_string($eventType) || trim($eventType) === '') {
            throw new PaymentGatewayException('Stripe webhook event is missing its ID or type.', 'stripe_webhook_event_invalid');
        }

        $record = $this->recordEvent($event, $eventId, $eventType);
        if ($record === null) {
            $existing = PaymentWebhookEvent::query()
                ->where('provider', PaymentProvider::Stripe->value)
                ->where('event_id', $eventId)
                ->first();

            // Only terminal events are safe to acknowledge as duplicates. An event
            // still marked processing/received may be in-flight or awaiting retry.
            if (! $existing || ! in_array($existing->status, ['processed', 'ignored', 'rejected'], true)) {
                throw new PaymentGatewayException(
                    'Stripe webhook event is already being processed or is awaiting retry.',
                    'stripe_webhook_event_in_progress',
                    503,
                );
            }

            return 'duplicate';
        }

        $supported = array_merge(self::SUCCESS_EVENTS, self::FAILURE_EVENTS, self::EXPIRED_EVENTS);
        if (! in_array($eventType, $supported, true)) {
            $this->markEvent($record, 'ignored');
            return 'ignored';
        }

        $session = data_get($event, 'data.object');
        $sessionId = is_array($session) ? ($session['id'] ?? null) : null;
        if (! is_array($session) || ! is_string($sessionId) || trim($sessionId) === '') {
            $this->markEvent($record, 'rejected', 'stripe_webhook_session_invalid');
            return 'rejected';
        }

        $payment = Payment::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->where('provider_reference', $sessionId)
            ->first();

        if (! $payment) {
            // Release the claim so Stripe can retry after the local payment write completes.
            $this->markEvent($record, 'received', 'stripe_webhook_payment_not_found');
            throw new PaymentGatewayException('No local payment matches this Stripe Checkout Session.', 'stripe_webhook_payment_not_found');
        }

        try {
            $gateway = $this->gateways->driver(PaymentProvider::Stripe);
            if (! $gateway instanceof StripeGateway) {
                throw new PaymentGatewayException('Configured Stripe driver cannot validate Checkout webhooks.', 'stripe_gateway_misconfigured');
            }

            $gateway->assertWebhookSessionMatchesPayment($session, $payment);

            if (in_array($eventType, self::SUCCESS_EVENTS, true)) {
                $result = $gateway->capture($payment);
                $this->finalizer->handle($payment, $result);
            } elseif (in_array($eventType, self::FAILURE_EVENTS, true)) {
                $this->finalizer->handle(
                    $payment,
                    $gateway->failedWebhookResult($session, $payment, $eventId, $eventType),
                );
            } else {
                DB::transaction(function () use ($payment, $eventId, $eventType): void {
                    $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                    if (! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
                        $locked->forceFill([
                            'status' => PaymentStatus::Cancelled,
                            'metadata' => array_merge($locked->metadata ?? [], [
                                'stripe_webhook_event_id' => $eventId,
                                'stripe_webhook_event_type' => $eventType,
                                'stripe_session_expired_at' => now()->toIso8601String(),
                            ]),
                        ])->save();
                    }
                });
            }

            $record->forceFill([
                'payment_id' => $payment->getKey(),
                'status' => 'processed',
                'error_code' => null,
                'processed_at' => now(),
            ])->save();

            return 'processed';
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof PaymentGatewayException
                ? (string) $exception->providerCode
                : 'stripe_webhook_processing_failed';
            $permanent = preg_match('/(invalid|mismatch|missing|unsupported)/i', $errorCode) === 1;

            $record->forceFill([
                'payment_id' => $payment->getKey(),
                'status' => $permanent ? 'rejected' : 'received',
                'error_code' => $errorCode,
                'processed_at' => $permanent ? now() : null,
            ])->save();

            if ($exception instanceof PaymentGatewayException) {
                throw $exception;
            }

            throw new PaymentGatewayException('Stripe webhook processing is temporarily unavailable.', 'stripe_webhook_processing_failed', null, $exception);
        }
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
                    // Claim the event before making any provider or payment calls.
                    'status' => 'processing',
                    'payload' => $event,
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // A concurrent request may have inserted the same event after our initial read.
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

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
