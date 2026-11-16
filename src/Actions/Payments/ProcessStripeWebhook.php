<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Ahmed\LaravelPayment\Payments\Stripe\StripeGateway;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessStripeWebhook
{
    private const SUCCESS_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
    ];

    private const FAILURE_EVENTS = [
        'checkout.session.async_payment_failed',
    ];

    private const EXPIRED_EVENTS = [
        'checkout.session.expired',
    ];

    public function __construct(
        private readonly CaptureStripeCheckoutSession $captureStripeCheckoutSession,
        private readonly FinalizeBookingPayment $finalizeBookingPayment,
        private readonly PaymentGatewayManager $gatewayManager,
    ) {}

    /**
     * Process a signature-verified Stripe event. Stripe may retry webhook events,
     * so event IDs are stored in the tenant database and handled idempotently.
     *
     * @param array<string, mixed> $event
     */
    public function handle(array $event): string
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;

        if (! is_string($eventId) || trim($eventId) === ''
            || ! is_string($eventType) || trim($eventType) === '') {
            throw new PaymentGatewayException(
                'Stripe webhook event is missing its ID or event type.',
                'stripe_webhook_event_invalid',
            );
        }

        $eventRecord = DB::transaction(function () use ($event, $eventId, $eventType): ?PaymentWebhookEvent {
            $existing = PaymentWebhookEvent::query()
                ->where('provider', PaymentProvider::Stripe->value)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status !== 'received') {
                return null;
            }

            if ($existing) {
                return $existing;
            }

            return PaymentWebhookEvent::query()->create([
                'provider' => PaymentProvider::Stripe->value,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'status' => 'received',
                'payload' => $event,
            ]);
        });

        if ($eventRecord === null) {
            return 'duplicate';
        }

        if (! in_array($eventType, array_merge(self::SUCCESS_EVENTS, self::FAILURE_EVENTS, self::EXPIRED_EVENTS), true)) {
            $this->markEvent($eventRecord, 'ignored');

            return 'ignored';
        }

        $session = data_get($event, 'data.object');

        if (! is_array($session) || ! is_string($session['id'] ?? null)) {
            $this->markEvent($eventRecord, 'rejected', 'stripe_webhook_session_invalid');

            return 'rejected';
        }

        $payment = BookingPayment::query()
            ->where('provider', PaymentProvider::Stripe->value)
            ->where('provider_reference', $session['id'])
            ->first();

        if (! $payment) {
            // Keep the event in `received` so Stripe can retry after a delayed DB write.
            throw new PaymentGatewayException(
                'The booking payment for this Stripe Checkout Session has not been found yet.',
                'stripe_webhook_payment_not_found',
            );
        }

        try {
            $gateway = $this->gatewayManager->driver(PaymentProvider::Stripe);

            if (! $gateway instanceof StripeGateway) {
                throw new PaymentGatewayException(
                    'The configured Stripe gateway cannot process Stripe webhook events.',
                    'stripe_gateway_misconfigured',
                );
            }

            $gateway->assertWebhookSessionMatchesPayment($session, $payment);

            if (in_array($eventType, self::SUCCESS_EVENTS, true)) {
                $this->captureStripeCheckoutSession->handle($payment, 'stripe_webhook');
            } elseif (in_array($eventType, self::FAILURE_EVENTS, true)) {
                $result = $gateway->failedWebhookResult($session, $payment, $eventId, $eventType);
                $this->finalizeBookingPayment->handle($payment, $result);
            } else {
                $this->expirePayment($payment, $eventId, $eventType);
            }

            $this->markEvent($eventRecord, 'processed');

            return 'processed';
        } catch (Throwable $exception) {
            $eventRecord->forceFill([
                'status' => 'received',
                'error_code' => $exception instanceof PaymentGatewayException
                    ? $exception->providerCode
                    : 'stripe_webhook_processing_failed',
                'processed_at' => null,
            ])->save();

            if ($exception instanceof PaymentGatewayException) {
                throw $exception;
            }

            throw new PaymentGatewayException(
                'Stripe webhook processing is temporarily unavailable.',
                'stripe_webhook_processing_failed',
                null,
                $exception,
            );
        }
    }

    private function expirePayment(BookingPayment $payment, string $eventId, string $eventType): void
    {
        DB::transaction(function () use ($payment, $eventId, $eventType): void {
            $lockedPayment = BookingPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedPayment->status, [
                PaymentStatus::Paid,
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
            ], true)) {
                return;
            }

            $lockedPayment->forceFill([
                'status' => PaymentStatus::Cancelled,
                'metadata' => array_merge($lockedPayment->metadata ?? [], [
                    'stripe_webhook_event_id' => $eventId,
                    'stripe_webhook_event_type' => $eventType,
                    'stripe_session_expired_at' => now()->toIso8601String(),
                ]),
            ])->save();
        });
    }

    private function markEvent(
        PaymentWebhookEvent $event,
        string $status,
        ?string $errorCode = null,
    ): void {
        $event->forceFill([
            'status' => $status,
            'error_code' => $errorCode,
            'processed_at' => now(),
        ])->save();
    }
}
