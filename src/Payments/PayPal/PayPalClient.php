<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\PayPal;

use Ahmed\LaravelPayment\Data\PayPalCredentialsData;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class PayPalClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly PayPalCredentialsResolver $credentialsResolver,
    ) {}

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function createOrder(array $payload, string $requestId): array
    {
        return $this->send('POST', '/v2/checkout/orders', $payload, $requestId);
    }

    /** @return array<string, mixed> */
    public function captureOrder(string $orderId, string $requestId): array
    {
        return $this->sendRawJson(
            'POST',
            '/v2/checkout/orders/'.rawurlencode($orderId).'/capture',
            '{}',
            $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function showOrder(string $orderId): array
    {
        if (trim($orderId) === '') {
            throw new PaymentGatewayException('A PayPal order ID is required.', 'paypal_order_reference_missing');
        }

        return $this->send('GET', '/v2/checkout/orders/'.rawurlencode($orderId));
    }

    /** Force an OAuth request to verify that the configured credentials can reach PayPal. */
    public function checkAvailability(): void
    {
        $this->accessToken($this->credentialsResolver->resolve(), forceRefresh: true);
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function refundCapture(string $captureId, array $payload, string $requestId): array
    {
        return $this->send(
            'POST',
            '/v2/payments/captures/'.rawurlencode($captureId).'/refund',
            $payload,
            $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function showRefund(string $refundId): array
    {
        return $this->send(
            'GET',
            '/v2/payments/refunds/'.rawurlencode($refundId),
        );
    }

    /**
     * Verify a PayPal webhook by asking PayPal to validate the original event.
     * Header keys must be lowercase, with scalar string values.
     *
     * @param array<string, string> $headers
     * @param string $rawEventBody The untouched original JSON body sent by PayPal.
     */
    public function verifyWebhookSignature(array $headers, string $rawEventBody): bool
    {
        $credentials = $this->credentialsResolver->resolve();

        if (! is_string($credentials->webhookId) || trim($credentials->webhookId) === '') {
            throw new PaymentGatewayException(
                'The PayPal webhook ID is not configured for this shop.',
                'paypal_webhook_id_missing',
            );
        }

        $requiredHeaders = [
            'paypal-auth-algo',
            'paypal-cert-url',
            'paypal-transmission-id',
            'paypal-transmission-sig',
            'paypal-transmission-time',
        ];

        foreach ($requiredHeaders as $header) {
            if (! isset($headers[$header]) || trim($headers[$header]) === '') {
                return false;
            }
        }

        try {
            // Encode the wrapper fields, then insert the untouched JSON event
            // instead of decoding and re-encoding it.
            $wrapper = json_encode([
                'auth_algo' => $headers['paypal-auth-algo'],
                'cert_url' => $headers['paypal-cert-url'],
                'transmission_id' => $headers['paypal-transmission-id'],
                'transmission_sig' => $headers['paypal-transmission-sig'],
                'transmission_time' => $headers['paypal-transmission-time'],
                'webhook_id' => $credentials->webhookId,
            ], JSON_THROW_ON_ERROR);

            if (! str_starts_with(ltrim($rawEventBody), '{') || ! str_ends_with(rtrim($rawEventBody), '}')) {
                return false;
            }

            $verificationBody = substr($wrapper, 0, -1)
                .',"webhook_event":'.$rawEventBody.'}';

            // Ensure the composed outer document is valid JSON without changing
            // the embedded event bytes that PayPal originally sent.
            json_decode($verificationBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PaymentGatewayException(
                'The PayPal webhook event body is not valid JSON.',
                'paypal_webhook_json_invalid',
                null,
                $exception,
            );
        }

        $response = $this->sendRawJson(
            'POST',
            '/v1/notifications/verify-webhook-signature',
            $verificationBody,
            credentials: $credentials,
        );

        return ($response['verification_status'] ?? null) === 'SUCCESS';
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        array $payload = [],
        ?string $requestId = null,
    ): array {
        $credentials = $this->credentialsResolver->resolve();
        $token = $this->accessToken($credentials);

        $pendingRequest = $this->http
            ->baseUrl($credentials->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout((int) config('payments.paypal.timeout', 20))
            ->connectTimeout((int) config('payments.paypal.connect_timeout', 10));

        if ($requestId !== null) {
            $pendingRequest = $pendingRequest->withHeaders([
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
            ]);
        }

        try {
            $options = strtoupper($method) === 'GET' ? [] : ['json' => $payload];
            $response = $pendingRequest->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal API connection failed.', [
                'method' => strtoupper($method),
                'exception' => $exception::class,
            ]);

            throw new PaymentGatewayException(
                'Could not communicate with PayPal. Check the connection and try again.',
                'paypal_connection_error',
                null,
                $exception,
            );
        }

        return $this->decodeResponse($response, 'PayPal API request');
    }

    /** @return array<string, mixed> */
    private function sendRawJson(
        string $method,
        string $path,
        string $body,
        ?string $requestId = null,
        ?PayPalCredentialsData $credentials = null,
    ): array {
        $credentials ??= $this->credentialsResolver->resolve();
        $token = $this->accessToken($credentials);

        $pendingRequest = $this->http
            ->baseUrl($credentials->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout((int) config('payments.paypal.timeout', 20))
            ->connectTimeout((int) config('payments.paypal.connect_timeout', 10));

        if ($requestId !== null) {
            $pendingRequest = $pendingRequest->withHeaders([
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
            ]);
        }

        try {
            $response = $pendingRequest
                ->withBody($body, 'application/json')
                ->send($method, $path);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal API connection failed.', [
                'method' => strtoupper($method),
                'exception' => $exception::class,
            ]);

            throw new PaymentGatewayException(
                'Could not communicate with PayPal. Check the connection and try again.',
                'paypal_connection_error',
                null,
                $exception,
            );
        }

        return $this->decodeResponse($response, 'PayPal API request');
    }

    private function accessToken(PayPalCredentialsData $credentials, bool $forceRefresh = false): string
    {
        $cacheKey = 'payments.paypal.access_token.'
            .$credentials->mode.'.'.hash('sha256', $credentials->clientId);

        $cachedToken = $forceRefresh ? null : $this->cache->get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        try {
            $response = $this->http
                ->baseUrl($credentials->baseUrl())
                ->acceptJson()
                ->asForm()
                ->withBasicAuth($credentials->clientId, $credentials->clientSecret)
                ->timeout((int) config('payments.paypal.timeout', 20))
                ->connectTimeout((int) config('payments.paypal.connect_timeout', 10))
                ->post('/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal OAuth connection failed.', [
                'mode' => $credentials->mode,
                'exception' => $exception::class,
            ]);

            throw new PaymentGatewayException(
                'Could not reach PayPal authentication. Check the network connection and try again.',
                'paypal_authentication_connection_error',
                null,
                $exception,
            );
        }

        $data = $this->decodeResponse($response, 'PayPal authentication');
        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new PaymentGatewayException(
                'PayPal did not return a valid access token.',
                'paypal_access_token_missing',
            );
        }

        $expiresIn = max(60, (int) ($data['expires_in'] ?? 300) - 60);

        $this->cache->put($cacheKey, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    /** @return array<string, mixed> */
    private function decodeResponse(Response $response, string $operation): array
    {
        $data = $response->json();

        if (! $response->successful()) {
            // PayPal's Orders API commonly returns `name` and `message`,
            // while its OAuth endpoint returns `error` and `error_description`.
            // Read both formats so authentication failures keep their real reason.
            $providerCode = 'paypal_api_error';
            $providerMessage = 'The provider returned an unsuccessful response.';

            if (is_array($data)) {
                foreach (['name', 'error', 'error_code'] as $key) {
                    if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                        $providerCode = trim($data[$key]);
                        break;
                    }
                }

                // Orders API errors such as ORDER_ALREADY_CAPTURED are nested
                // under `details[*].issue`, while the top-level name is often
                // only the generic UNPROCESSABLE_ENTITY value.
                if (in_array(strtoupper($providerCode), ['UNPROCESSABLE_ENTITY', 'PAYPAL_API_ERROR'], true)
                    && is_array($data['details'] ?? null)) {
                    foreach ($data['details'] as $detail) {
                        if (is_array($detail) && is_string($detail['issue'] ?? null)
                            && trim($detail['issue']) !== '') {
                            $providerCode = trim($detail['issue']);
                            break;
                        }
                    }
                }

                foreach (['message', 'error_description', 'error_summary'] as $key) {
                    if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                        $providerMessage = trim($data[$key]);
                        break;
                    }
                }

                if ($providerMessage === 'The provider returned an unsuccessful response.'
                    && is_array($data['details'] ?? null)) {
                    foreach ($data['details'] as $detail) {
                        if (is_array($detail) && is_string($detail['description'] ?? null)
                            && trim($detail['description']) !== '') {
                            $providerMessage = trim($detail['description']);
                            break;
                        }
                    }
                }
            }

            throw new PaymentGatewayException(
                sprintf('%s failed (HTTP %d): %s', $operation, $response->status(), $providerMessage),
                $providerCode,
                $response->status(),
            );
        }

        if (! is_array($data)) {
            throw new PaymentGatewayException(
                'PayPal returned an invalid response format.',
                'paypal_invalid_response',
                $response->status(),
            );
        }

        return $data;
    }
}
