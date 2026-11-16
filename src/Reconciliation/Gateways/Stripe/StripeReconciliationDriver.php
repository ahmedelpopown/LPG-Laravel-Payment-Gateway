<?php

namespace Ahmed\LaravelPayment\Reconciliation\Gateways\Stripe;

use Ahmed\LaravelPayment\Reconciliation\Contracts\GatewayCredentialsResolver;
use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationGatewayDriver;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentPage;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\Exceptions\ReconciliationException;
use DateTimeImmutable;
use DateTimeInterface;
use Stripe\StripeClient;
use Throwable;

final class StripeReconciliationDriver implements ReconciliationGatewayDriver
{
    public function __construct(private readonly GatewayCredentialsResolver $credentials)
    {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function retrieve(string $providerReference): ?ProviderPaymentSnapshot
    {
        $referenceType = config('laravel-payment-reconciliation.gateways.stripe.reference_type', 'payment_intent');

        if ($referenceType === 'checkout_session' || str_starts_with($providerReference, 'cs_')) {
            return $this->retrieveCheckoutSession($providerReference);
        }

        $client = $this->client();

        try {
            $intent = $client->paymentIntents->retrieve($providerReference, [
                'expand' => ['latest_charge'],
            ]);
        } catch (Throwable $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new ReconciliationException('Stripe could not retrieve the PaymentIntent: '.$exception->getMessage(), 0, $exception);
        }

        $latestCharge = $intent->latest_charge ?? null;

        if (is_string($latestCharge) && $latestCharge !== '') {
            try {
                $latestCharge = $client->charges->retrieve($latestCharge, []);
            } catch (Throwable $exception) {
                throw new ReconciliationException('Stripe could not retrieve the PaymentIntent latest Charge: '.$exception->getMessage(), 0, $exception);
            }
        }

        $refunded = is_object($latestCharge) ? (int) ($latestCharge->amount_refunded ?? 0) : 0;
        $amount = (int) (($intent->amount_received ?? 0) > 0 ? $intent->amount_received : ($intent->amount ?? 0));

        return new ProviderPaymentSnapshot(
            gateway: $this->key(),
            reference: (string) $intent->id,
            status: $this->normalizeStatus((string) $intent->status),
            amountMinor: $amount,
            currency: strtoupper((string) $intent->currency),
            refundedAmountMinor: is_object($latestCharge) ? $refunded : null,
            createdAt: isset($intent->created) ? (new DateTimeImmutable())->setTimestamp((int) $intent->created) : null,
            raw: [
                'provider_status' => (string) $intent->status,
                'amount' => (int) ($intent->amount ?? 0),
                'amount_received' => (int) ($intent->amount_received ?? 0),
                'latest_charge' => is_object($latestCharge) ? (string) ($latestCharge->id ?? '') : null,
            ],
        );
    }

    public function listTransactions(DateTimeInterface $from, DateTimeInterface $to, ?string $cursor = null): ProviderPaymentPage
    {
        $params = [
            'limit' => 100,
            'created' => [
                'gte' => $from->getTimestamp(),
                'lte' => $to->getTimestamp(),
            ],
        ];

        if ($cursor !== null && $cursor !== '') {
            $params['starting_after'] = $cursor;
        }

        try {
            $referenceType = config('laravel-payment-reconciliation.gateways.stripe.reference_type', 'payment_intent');
            $page = $referenceType === 'checkout_session'
                ? $this->client()->checkout->sessions->all($params)
                : $this->client()->paymentIntents->all($params);
            $items = [];

            foreach ($page->data as $resource) {
                if ($referenceType === 'checkout_session') {
                    $paymentStatus = (string) ($resource->payment_status ?? 'unpaid');
                    $sessionStatus = (string) ($resource->status ?? '');

                    // Do not report abandoned open/expired Checkout Sessions as missing local payments.
                    // Local rows for these Sessions are still checked in the second (local-to-provider) pass.
                    if ($sessionStatus !== 'complete' && ! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
                        continue;
                    }

                    $items[] = new ProviderPaymentSnapshot(
                        gateway: $this->key(),
                        reference: (string) $resource->id,
                        status: $this->normalizeCheckoutSessionStatus($paymentStatus, $sessionStatus),
                        amountMinor: (int) ($resource->amount_total ?? 0),
                        currency: strtoupper((string) ($resource->currency ?? '')),
                        refundedAmountMinor: null,
                        createdAt: isset($resource->created) ? (new DateTimeImmutable())->setTimestamp((int) $resource->created) : null,
                        raw: [
                            'session_status' => $sessionStatus,
                            'payment_status' => $paymentStatus,
                            'payment_intent' => is_string($resource->payment_intent ?? null) ? $resource->payment_intent : null,
                            'refund_data_complete' => false,
                        ],
                    );
                    continue;
                }

                $amountReceived = (int) ($resource->amount_received ?? 0);
                $items[] = new ProviderPaymentSnapshot(
                    gateway: $this->key(),
                    reference: (string) $resource->id,
                    status: $this->normalizeStatus((string) $resource->status),
                    amountMinor: $amountReceived > 0 ? $amountReceived : (int) ($resource->amount ?? 0),
                    currency: strtoupper((string) $resource->currency),
                    refundedAmountMinor: null,
                    createdAt: isset($resource->created) ? (new DateTimeImmutable())->setTimestamp((int) $resource->created) : null,
                    raw: [
                        'provider_status' => (string) $resource->status,
                        'amount' => (int) ($resource->amount ?? 0),
                        'amount_received' => $amountReceived,
                        'refund_data_complete' => false,
                    ],
                );
            }

            $last = $items === [] ? null : $items[array_key_last($items)]->reference;

            return new ProviderPaymentPage(
                items: $items,
                nextCursor: $last,
                hasMore: (bool) ($page->has_more ?? false),
            );
        } catch (Throwable $exception) {
            throw new ReconciliationException('Stripe transaction listing failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function retrieveCheckoutSession(string $sessionId): ?ProviderPaymentSnapshot
    {
        $client = $this->client();

        try {
            $session = $client->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent.latest_charge'],
            ]);
        } catch (Throwable $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new ReconciliationException('Stripe could not retrieve the Checkout Session: '.$exception->getMessage(), 0, $exception);
        }

        $paymentIntent = $session->payment_intent ?? null;
        if (is_string($paymentIntent) && $paymentIntent !== '') {
            try {
                $paymentIntent = $client->paymentIntents->retrieve($paymentIntent, [
                    'expand' => ['latest_charge'],
                ]);
            } catch (Throwable $exception) {
                throw new ReconciliationException('Stripe could not retrieve the Checkout Session PaymentIntent: '.$exception->getMessage(), 0, $exception);
            }
        }

        $latestCharge = is_object($paymentIntent) ? ($paymentIntent->latest_charge ?? null) : null;
        if (is_string($latestCharge) && $latestCharge !== '') {
            try {
                $latestCharge = $client->charges->retrieve($latestCharge, []);
            } catch (Throwable $exception) {
                throw new ReconciliationException('Stripe could not retrieve the Checkout Session latest Charge: '.$exception->getMessage(), 0, $exception);
            }
        }

        $paymentStatus = (string) ($session->payment_status ?? 'unpaid');
        $sessionStatus = (string) ($session->status ?? '');
        $amount = (int) ($session->amount_total ?? 0);

        return new ProviderPaymentSnapshot(
            gateway: $this->key(),
            reference: (string) $session->id,
            status: $this->normalizeCheckoutSessionStatus($paymentStatus, $sessionStatus),
            amountMinor: $amount,
            currency: strtoupper((string) ($session->currency ?? '')),
            refundedAmountMinor: is_object($latestCharge) ? (int) ($latestCharge->amount_refunded ?? 0) : null,
            createdAt: isset($session->created) ? (new DateTimeImmutable())->setTimestamp((int) $session->created) : null,
            raw: [
                'session_status' => $sessionStatus,
                'payment_status' => $paymentStatus,
                'payment_intent' => is_object($paymentIntent) ? (string) ($paymentIntent->id ?? '') : null,
                'latest_charge' => is_object($latestCharge) ? (string) ($latestCharge->id ?? '') : null,
            ],
        );
    }

    private function normalizeCheckoutSessionStatus(string $paymentStatus, string $sessionStatus): string
    {
        if ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required') {
            return 'succeeded';
        }

        if ($sessionStatus === 'expired') {
            return 'canceled';
        }

        return 'pending';
    }

    private function client(): StripeClient
    {
        $secret = $this->credentials->credentialsFor('stripe')['secret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw new ReconciliationException('Stripe secret credential is missing.');
        }

        // Do not cache this client in a singleton driver: credentials may be tenant-specific.
        return new StripeClient($secret);
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'succeeded' => 'succeeded',
            'processing' => 'pending',
            'requires_capture' => 'authorized',
            'canceled' => 'canceled',
            'requires_action', 'requires_confirmation', 'requires_payment_method' => 'pending',
            default => 'unknown',
        };
    }

    private function isNotFound(Throwable $exception): bool
    {
        return method_exists($exception, 'getHttpStatus') && $exception->getHttpStatus() === 404;
    }
}
