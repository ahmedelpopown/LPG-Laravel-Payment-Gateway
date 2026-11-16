<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Core\Actions;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class CreatePayment
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * Create a generic payment and its provider checkout session.
     *
     * @param array<string, mixed> $metadata
     */
    public function handle(
        PaymentProvider|string $provider,
        string $amount,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
        ?Model $payable = null,
    ): Payment {
        $provider = $provider instanceof PaymentProvider
            ? $provider
            : PaymentProvider::tryFrom(strtolower(trim($provider)));

        if ($payable !== null && (! $payable->exists || $payable->getKey() === null)) {
            throw new InvalidArgumentException('The payable model must be persisted before creating a payment.');
        }

        if (! $provider) {
            throw new InvalidArgumentException('Unsupported payment provider.');
        }

        $amount = $this->normalizeAmount($amount);
        $currency = strtoupper(trim($currency));
        $idempotencyKey = trim($idempotencyKey);

        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO code.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new InvalidArgumentException('A non-empty idempotency key of at most 100 characters is required.');
        }

        $gateway = $this->gateways->driver($provider);

        if (! $gateway->supportsCurrency($currency)) {
            throw new PaymentGatewayException("{$provider->value} does not support {$currency}.", 'payment_currency_not_supported');
        }

        try {
            $payment = DB::transaction(function () use ($provider, $amount, $currency, $idempotencyKey, $metadata, $payable): Payment {
                $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                if ($existing) {
                    $this->assertSameIdempotentRequest($existing, $provider, $amount, $currency, $payable);

                    return $existing;
                }

                return Payment::query()->create([
                    'payable_type' => $payable?->getMorphClass(),
                    'payable_id' => $payable ? (string) $payable->getKey() : null,
                    'provider' => $provider,
                    'idempotency_key' => $idempotencyKey,
                    'amount' => $amount,
                    'refunded_amount' => '0.00',
                    'currency' => $currency,
                    'status' => PaymentStatus::Pending,
                    'metadata' => $metadata,
                ]);
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)
                && ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw $exception;
            }

            // A concurrent request may have inserted the same idempotency key first.
            $payment = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if (! $payment) {
                throw $exception;
            }

            $this->assertSameIdempotentRequest($payment, $provider, $amount, $currency, $payable);
        }

        if ($payment->checkout_url) {
            return $payment;
        }

        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)) {
            throw new PaymentGatewayException('This payment is not in a state that can create a checkout session.', 'payment_not_checkoutable');
        }

        try {
            $checkout = $gateway->createCheckout($payment);

            $payment->forceFill([
                'provider_reference' => $checkout->providerReference,
                'checkout_url' => $checkout->checkoutUrl,
                'metadata' => array_merge($payment->metadata ?? [], $checkout->metadata, [
                    'checkout_created_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $payment->fresh();
        } catch (Throwable $exception) {
            $providerCode = $exception instanceof PaymentGatewayException
                ? (string) $exception->providerCode
                : '';
            $retryable = $exception instanceof PaymentGatewayException
                && (str_contains($providerCode, 'connection')
                    || in_array($providerCode, [
                        'stripe_secret_key_missing',
                        'paypal_credentials_missing',
                        'paypal_mode_invalid',
                    ], true)
                    || in_array($exception->httpStatus, [408, 429, 401, 403], true)
                    || ($exception->httpStatus !== null && $exception->httpStatus >= 500));

            $payment->forceFill([
                'status' => $retryable ? PaymentStatus::Pending : PaymentStatus::Failed,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'checkout_error_code' => $exception instanceof PaymentGatewayException
                        ? $exception->providerCode
                        : 'checkout_initialization_failed',
                    'checkout_failed_at' => now()->toIso8601String(),
                    'checkout_retryable' => $retryable,
                ]),
            ])->save();

            throw $exception;
        }
    }


    private function assertSameIdempotentRequest(
        Payment $existing,
        PaymentProvider $provider,
        string $amount,
        string $currency,
        ?Model $payable,
    ): void {
        $samePayable = $existing->payable_type === ($payable?->getMorphClass())
            && (string) $existing->payable_id === (string) $payable?->getKey();

        if ($existing->provider !== $provider
            || $this->normalizeAmount((string) $existing->amount) !== $amount
            || strtoupper((string) $existing->currency) !== $currency
            || ! $samePayable) {
            throw new PaymentGatewayException('Idempotency key was already used for a different payment.', 'payment_idempotency_conflict');
        }
    }

    private function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);

        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Amount must be a positive decimal with up to two decimal places.');
        }

        $minor = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');

        if ($minor <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
