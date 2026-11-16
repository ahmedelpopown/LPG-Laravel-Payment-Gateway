<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\Stripe;

use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient as StripeSdkClient;
use Throwable;

/**
 * Small boundary around stripe-php. Provider SDK calls stay out of controllers
 * and booking actions, and tenant credentials are resolved for every request.
 */
final class StripeClient
{
    public function __construct(
        private readonly StripeCredentialsResolver $credentialsResolver,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $payload, string $idempotencyKey): array
    {
        try {
            $session = $this->client()->checkout->sessions->create(
                $payload,
                ['idempotency_key' => $idempotencyKey],
            );

            return $session->toArray();
        } catch (PaymentGatewayException $exception) {
            // Keep application errors (missing tenant/key, invalid tenant context) intact.
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->providerException('create_checkout_session', $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        try {
            $session = $this->client()->checkout->sessions->retrieve($sessionId);

            return $session->toArray();
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->providerException('retrieve_checkout_session', $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRefund(array $payload, string $idempotencyKey): array
    {
        try {
            $refund = $this->client()->refunds->create(
                $payload,
                ['idempotency_key' => $idempotencyKey],
            );

            return $refund->toArray();
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->providerException('create_refund', $exception);
        }
    }

    /**
     * Return every refund belonging to a Stripe Charge, following all pages.
     *
     * The embedded Charge.refunds collection only contains a limited number of
     * recent refunds. This method is used by charge.refunded webhooks so the
     * local ledger can be reconciled against the complete provider list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRefundsForCharge(string $chargeId): array
    {
        try {
            $stripe = $this->client();
            $parameters = [
                'charge' => $chargeId,
                'limit' => 100,
            ];
            $refunds = [];

            do {
                $page = $stripe->refunds->all($parameters)->toArray();
                $data = $page['data'] ?? [];

                if (! is_array($data)) {
                    throw new \UnexpectedValueException('Stripe returned an invalid refunds collection.');
                }

                foreach ($data as $refund) {
                    if (is_object($refund) && method_exists($refund, 'toArray')) {
                        $refund = $refund->toArray();
                    }

                    if (is_array($refund)) {
                        $refunds[] = $refund;
                    }
                }

                $hasMore = (bool) ($page['has_more'] ?? false);
                $lastRefund = $data === [] ? null : end($data);
                if (is_object($lastRefund) && method_exists($lastRefund, 'toArray')) {
                    $lastRefund = $lastRefund->toArray();
                }
                $lastId = is_array($lastRefund) ? ($lastRefund['id'] ?? null) : null;

                if (! $hasMore) {
                    break;
                }

                if (! is_string($lastId) || trim($lastId) === '') {
                    throw new \UnexpectedValueException('Stripe indicated more refunds but returned no pagination cursor.');
                }

                $parameters['starting_after'] = $lastId;
            } while (true);

            return $refunds;
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->providerException('list_refunds_for_charge', $exception);
        }
    }

    /**
     * @param array<string, string[]> $options
     * @return array<string, mixed>
     */
    public function retrievePaymentIntent(string $paymentIntentId, array $options = []): array
    {
        try {
            $intent = $this->client()->paymentIntents->retrieve($paymentIntentId, $options);

            return $intent->toArray();
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->providerException('retrieve_payment_intent', $exception);
        }
    }

    private function client(): StripeSdkClient
    {
        $credentials = $this->credentialsResolver->resolve();

        return new StripeSdkClient([
            'api_key' => $credentials->secretKey,
            'max_network_retries' => 2,
        ]);
    }

    private function providerException(string $operation, Throwable $exception): PaymentGatewayException
    {
        $status = null;
        if (method_exists($exception, 'getHttpStatus')) {
            $candidate = $exception->getHttpStatus();
            $status = is_int($candidate) ? $candidate : null;
        }

        $requestId = method_exists($exception, 'getRequestId')
            ? $exception->getRequestId()
            : null;

        $stripeError = method_exists($exception, 'getError')
            ? $exception->getError()
            : null;

        $stripeErrorType = $this->readErrorField($stripeError, 'type');
        $stripeErrorCode = $this->readErrorField($stripeError, 'code');
        $stripeErrorParam = $this->readErrorField($stripeError, 'param');
        $stripeErrorMessage = $this->readErrorField($stripeError, 'message');

        $stripeCode = method_exists($exception, 'getStripeCode')
            ? $exception->getStripeCode()
            : $stripeErrorCode;

        // Never log API secrets if an SDK error happens to echo one in its message.
        $safeExceptionMessage = $this->redactSecrets($exception->getMessage());
        $safeStripeErrorMessage = $stripeErrorMessage !== null
            ? $this->redactSecrets($stripeErrorMessage)
            : null;

        $tenantId = null;
        try {
            $tenantId = tenancy()->tenant?->getTenantKey();
        } catch (Throwable) {
            // Logging must never replace the original provider exception.
        }

        Log::error('Stripe API request failed.', [
            'operation' => $operation,
            'tenant_id' => $tenantId,
            'exception_class' => $exception::class,
            'http_status' => $status,
            'stripe_request_id' => is_string($requestId) ? $requestId : null,
            'stripe_error_type' => $stripeErrorType,
            'stripe_error_code' => is_string($stripeCode) ? $stripeCode : null,
            'stripe_error_param' => $stripeErrorParam,
            'stripe_error_message' => $safeStripeErrorMessage,
            'exception_message' => $safeExceptionMessage,
        ]);

        $connectionError = $status === null;

        return new PaymentGatewayException(
            "Stripe {$operation} failed. Check the Stripe API response and the tenant credentials.",
            $connectionError ? 'stripe_connection_error' : 'stripe_api_error',
            $status,
            $exception,
        );
    }

    private function readErrorField(mixed $error, string $field): ?string
    {
        $value = is_array($error)
            ? ($error[$field] ?? null)
            : (is_object($error) ? ($error->{$field} ?? null) : null);

        return is_scalar($value) ? (string) $value : null;
    }

    private function redactSecrets(string $message): string
    {
        return preg_replace(
            '/\b(?:sk|rk)_(?:test|live)_[A-Za-z0-9]+\b|\bwhsec_[A-Za-z0-9]+\b/i',
            '[REDACTED_STRIPE_SECRET]',
            $message,
        ) ?? '[Stripe error message redaction failed]';
    }
}
