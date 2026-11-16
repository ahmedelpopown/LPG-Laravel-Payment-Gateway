<?php

namespace Ahmed\LaravelPayment\Reconciliation\Gateways\PayPal;

use Ahmed\LaravelPayment\Reconciliation\Contracts\GatewayCredentialsResolver;
use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationGatewayDriver;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentPage;
use Ahmed\LaravelPayment\Reconciliation\DTOs\ProviderPaymentSnapshot;
use Ahmed\LaravelPayment\Reconciliation\Exceptions\ReconciliationException;
use Ahmed\LaravelPayment\Reconciliation\Services\Money;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PayPalReconciliationDriver implements ReconciliationGatewayDriver
{
    public function __construct(private readonly GatewayCredentialsResolver $credentials)
    {
    }

    public function key(): string
    {
        return 'paypal';
    }

    /** The local provider reference can be a Capture ID or Order ID, selected by config. */
    public function retrieve(string $providerReference): ?ProviderPaymentSnapshot
    {
        $referenceType = config('laravel-payment-reconciliation.gateways.paypal.reference_type', 'capture');

        if ($referenceType === 'order') {
            return $this->retrieveOrder($providerReference);
        }

        try {
            [$baseUrl, $accessToken] = $this->requestContext();
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get($baseUrl.'/v2/payments/captures/'.rawurlencode($providerReference));

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                throw new ReconciliationException('PayPal capture lookup failed with HTTP '.$response->status().'.');
            }

            $data = $response->json();
            $currency = strtoupper((string) data_get($data, 'amount.currency_code', ''));
            $amount = Money::decimalToMinor((string) data_get($data, 'amount.value', '0'), $currency, (array) config('laravel-payment-reconciliation.currency_scales', []));
            $status = strtoupper((string) data_get($data, 'status', ''));
            $refunded = match ($status) {
                'REFUNDED' => $amount,
                'PARTIALLY_REFUNDED' => null,
                default => 0,
            };

            return new ProviderPaymentSnapshot(
                gateway: $this->key(),
                reference: (string) data_get($data, 'id', $providerReference),
                status: $this->normalizeCaptureStatus($status),
                amountMinor: $amount,
                currency: $currency,
                refundedAmountMinor: $refunded,
                createdAt: isset($data['create_time']) ? new DateTimeImmutable($data['create_time']) : null,
                raw: [
                    'provider_status' => $status,
                    'capture_status_details' => data_get($data, 'status_details.reason'),
                    'partial_refund_amount_complete' => $status !== 'PARTIALLY_REFUNDED',
                ],
            );
        } catch (Throwable $exception) {
            if ($exception instanceof ReconciliationException) {
                throw $exception;
            }

            throw new ReconciliationException('PayPal capture retrieval failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    public function listTransactions(DateTimeInterface $from, DateTimeInterface $to, ?string $cursor = null): ProviderPaymentPage
    {
        $fromUtc = DateTimeImmutable::createFromInterface($from);
        $toUtc = DateTimeImmutable::createFromInterface($to);

        if ($toUtc > $fromUtc->modify('+31 days')) {
            throw new ReconciliationException('PayPal Transaction Search supports a maximum date range of 31 days per request. Split longer periods into smaller runs.');
        }

        $pageNumber = max(1, (int) ($cursor ?? 1));

        try {
            [$baseUrl, $accessToken] = $this->requestContext();
            $response = Http::withToken($accessToken)
                ->withHeaders(['PayPal-Enforce-ISO8601-Format' => 'true'])
                ->acceptJson()
                ->get($baseUrl.'/v1/reporting/transactions', [
                    'start_date' => $fromUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                    'end_date' => $toUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                    'fields' => 'transaction_info',
                    'balance_affecting_records_only' => 'Y',
                    'page_size' => 100,
                    'page' => $pageNumber,
                ]);

            if (! $response->successful()) {
                throw new ReconciliationException('PayPal transaction search failed with HTTP '.$response->status().'.');
            }

            $payload = $response->json();
            $itemsByReference = [];
            $referenceType = config('laravel-payment-reconciliation.gateways.paypal.reference_type', 'capture');

            foreach ((array) data_get($payload, 'transaction_details', []) as $entry) {
                $info = (array) data_get($entry, 'transaction_info', []);
                $transactionId = (string) ($info['transaction_id'] ?? '');
                $paypalReferenceType = strtoupper((string) ($info['paypal_reference_id_type'] ?? ''));
                $reference = $referenceType === 'order'
                    ? ($paypalReferenceType === 'ODR' ? (string) ($info['paypal_reference_id'] ?? '') : '')
                    : $transactionId;
                $currency = strtoupper((string) data_get($info, 'transaction_amount.currency_code', ''));
                if ($reference === '' || $currency === '') {
                    continue;
                }

                $eventCode = strtoupper((string) ($info['transaction_event_code'] ?? ''));
                $allowedEventCodes = (array) config('laravel-payment-reconciliation.paypal.payment_event_codes', []);
                if (! in_array($eventCode, $allowedEventCodes, true)) {
                    continue;
                }

                $statusCode = strtoupper((string) ($info['transaction_status'] ?? ''));
                $amount = Money::decimalToMinor((string) data_get($info, 'transaction_amount.value', '0'), $currency, (array) config('laravel-payment-reconciliation.currency_scales', []));
                // For a merchant account, negative reporting rows represent money moving out, not customer charges.
                if ($amount < 0) {
                    continue;
                }

                $normalizedStatus = $this->normalizeSearchStatus($statusCode);
                $captureRow = [
                    'amount_minor' => $amount,
                    'status' => $normalizedStatus,
                    // Transaction Search's V (reversed) status does not prove a full refund amount.
                    'refunded_amount_minor' => null,
                ];

                $snapshot = new ProviderPaymentSnapshot(
                    gateway: $this->key(),
                    reference: $reference,
                    status: $normalizedStatus,
                    amountMinor: $amount,
                    currency: $currency,
                    refundedAmountMinor: null,
                    createdAt: isset($info['transaction_initiation_date']) ? new DateTimeImmutable($info['transaction_initiation_date']) : null,
                    raw: [
                        'provider_status' => $statusCode,
                        'transaction_event_code' => $info['transaction_event_code'] ?? null,
                        'paypal_reference_id' => $info['paypal_reference_id'] ?? null,
                        'paypal_reference_id_type' => $paypalReferenceType,
                        'capture_transaction_ids' => $referenceType === 'order' && $transactionId !== '' ? [$transactionId] : [],
                        'capture_transaction_amounts' => $referenceType === 'order' && $transactionId !== '' ? [$transactionId => $captureRow] : [],
                    ],
                );

                if ($referenceType === 'order' && isset($itemsByReference[$reference])) {
                    $existing = $itemsByReference[$reference];
                    if ($existing->currency !== $currency) {
                        throw new ReconciliationException('A PayPal Order was reported with multiple currencies; it requires manual review.');
                    }

                    $raw = $existing->raw;
                    $captureAmounts = (array) ($raw['capture_transaction_amounts'] ?? []);
                    if ($transactionId !== '' && isset($captureAmounts[$transactionId])) {
                        // PayPal reports can repeat a transaction row; one Capture must count once.
                        continue;
                    }

                    if ($transactionId !== '') {
                        $captureAmounts[$transactionId] = $captureRow;
                    }
                    $raw['capture_transaction_amounts'] = $captureAmounts;
                    $raw['capture_transaction_ids'] = array_keys($captureAmounts);
                    $statuses = array_map(
                        fn (array $row): string => (string) ($row['status'] ?? 'unknown'),
                        array_values($captureAmounts),
                    );
                    $itemsByReference[$reference] = new ProviderPaymentSnapshot(
                        gateway: $this->key(),
                        reference: $reference,
                        status: $this->mergeOrderStatuses($statuses),
                        amountMinor: array_sum(array_map(
                            fn (array $row): int => (int) ($row['amount_minor'] ?? 0),
                            array_values($captureAmounts),
                        )),
                        currency: $currency,
                        refundedAmountMinor: null,
                        createdAt: $existing->createdAt ?? $snapshot->createdAt,
                        raw: $raw,
                    );
                } else {
                    $itemsByReference[$reference] = $snapshot;
                }
            }

            $totalPages = (int) data_get($payload, 'total_pages', $pageNumber);
            $hasMore = $pageNumber < $totalPages;

            return new ProviderPaymentPage(
                items: array_values($itemsByReference),
                nextCursor: $hasMore ? (string) ($pageNumber + 1) : null,
                hasMore: $hasMore,
            );
        } catch (Throwable $exception) {
            if ($exception instanceof ReconciliationException) {
                throw $exception;
            }

            throw new ReconciliationException('PayPal transaction search failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function retrieveOrder(string $orderId): ?ProviderPaymentSnapshot
    {
        try {
            [$baseUrl, $accessToken] = $this->requestContext();
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get($baseUrl.'/v2/checkout/orders/'.rawurlencode($orderId));

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                throw new ReconciliationException('PayPal order lookup failed with HTTP '.$response->status().'.');
            }

            $data = $response->json();
            $captures = [];
            foreach ((array) data_get($data, 'purchase_units', []) as $unit) {
                foreach ((array) data_get($unit, 'payments.captures', []) as $capture) {
                    $captures[] = $capture;
                }
            }

            $currency = '';
            $amountMinor = 0;
            $refundedMinor = 0;
            $refundAmountComplete = true;
            $statuses = [];
            $captureIds = [];

            foreach ($captures as $capture) {
                $captureCurrency = strtoupper((string) data_get($capture, 'amount.currency_code', ''));
                if ($captureCurrency === '') {
                    continue;
                }
                if ($currency !== '' && $currency !== $captureCurrency) {
                    throw new ReconciliationException('A PayPal Order contains captures in different currencies; it requires manual review.');
                }

                $currency = $captureCurrency;
                $captureAmount = Money::decimalToMinor(
                    (string) data_get($capture, 'amount.value', '0'),
                    $currency,
                    (array) config('laravel-payment-reconciliation.currency_scales', []),
                );
                $amountMinor += $captureAmount;

                $captureStatus = strtoupper((string) data_get($capture, 'status', ''));
                $statuses[] = $this->normalizeCaptureStatus($captureStatus);
                if ($captureStatus === 'REFUNDED') {
                    $refundedMinor += $captureAmount;
                } elseif ($captureStatus === 'PARTIALLY_REFUNDED') {
                    $refundAmountComplete = false;
                }
                $captureIds[] = (string) data_get($capture, 'id', '');
            }

            if ($currency === '') {
                foreach ((array) data_get($data, 'purchase_units', []) as $unit) {
                    $unitCurrency = strtoupper((string) data_get($unit, 'amount.currency_code', ''));
                    if ($unitCurrency === '') {
                        continue;
                    }
                    if ($currency !== '' && $currency !== $unitCurrency) {
                        throw new ReconciliationException('A PayPal Order contains purchase units in different currencies; it requires manual review.');
                    }
                    $currency = $unitCurrency;
                    $amountMinor += Money::decimalToMinor(
                        (string) data_get($unit, 'amount.value', '0'),
                        $currency,
                        (array) config('laravel-payment-reconciliation.currency_scales', []),
                    );
                }
            }

            if ($currency === '') {
                throw new ReconciliationException('PayPal Order response did not include an amount currency.');
            }

            $orderStatus = strtoupper((string) data_get($data, 'status', ''));
            $status = $statuses !== []
                ? $this->mergeOrderStatuses($statuses)
                : match ($orderStatus) {
                    'COMPLETED' => 'succeeded',
                    'CREATED', 'SAVED', 'APPROVED' => 'pending',
                    'VOIDED' => 'canceled',
                    default => 'unknown',
                };

            return new ProviderPaymentSnapshot(
                gateway: $this->key(),
                reference: (string) data_get($data, 'id', $orderId),
                status: $status,
                amountMinor: $amountMinor,
                currency: $currency,
                refundedAmountMinor: $refundAmountComplete ? $refundedMinor : null,
                createdAt: isset($data['create_time']) ? new DateTimeImmutable($data['create_time']) : null,
                raw: [
                    'order_status' => $orderStatus,
                    'capture_ids' => array_values(array_filter($captureIds)),
                    'capture_count' => count($captures),
                    'partial_refund_amounts_complete' => $refundAmountComplete,
                ],
            );
        } catch (Throwable $exception) {
            if ($exception instanceof ReconciliationException) {
                throw $exception;
            }

            throw new ReconciliationException('PayPal order retrieval failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    /** @param list<string> $statuses */
    private function mergeOrderStatuses(array $statuses): string
    {
        if ($statuses === [] || in_array('unknown', $statuses, true)) {
            return 'unknown';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'refunded')) === count($statuses)) {
            return 'refunded';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'reversed')) === count($statuses)) {
            return 'reversed';
        }

        if (in_array('reversed', $statuses, true)) {
            return 'unknown';
        }

        // An order with a pending capture is not fully settled, even if another capture completed.
        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }

        if (in_array('partially_refunded', $statuses, true)
            || (in_array('refunded', $statuses, true) && in_array('succeeded', $statuses, true))) {
            return 'partially_refunded';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'succeeded')) === count($statuses)) {
            return 'succeeded';
        }

        if (count(array_filter($statuses, fn (string $status) => $status === 'failed')) === count($statuses)) {
            return 'failed';
        }

        // Mixed success/failure/cancellation outcomes need a person to inspect the order.
        return 'unknown';
    }

    /** @return array{0: string, 1: string} [base URL, OAuth access token] */
    private function requestContext(): array
    {
        $credentials = $this->credentials->credentialsFor('paypal');
        $clientId = $credentials['client_id'] ?? null;
        $clientSecret = $credentials['client_secret'] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new ReconciliationException('PayPal client_id/client_secret credentials are missing.');
        }

        $baseUrl = strtolower((string) ($credentials['environment'] ?? 'sandbox')) === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $cacheKey = 'laravel-payment:paypal:oauth:'.hash('sha256', $clientId.'|'.$clientSecret.'|'.$baseUrl);
        $accessToken = Cache::remember($cacheKey, now()->addMinutes(8), function () use ($clientId, $clientSecret, $baseUrl): string {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->post($baseUrl.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new ReconciliationException('PayPal could not issue an OAuth access token.');
            }

            return $response->json('access_token');
        });

        return [$baseUrl, $accessToken];
    }

    private function normalizeCaptureStatus(string $status): string
    {
        return match ($status) {
            'COMPLETED' => 'succeeded',
            'PENDING' => 'pending',
            'DECLINED', 'FAILED' => 'failed',
            'REFUNDED' => 'refunded',
            'PARTIALLY_REFUNDED' => 'partially_refunded',
            default => 'unknown',
        };
    }

    private function normalizeSearchStatus(string $status): string
    {
        return match ($status) {
            'S' => 'succeeded',
            'P' => 'pending',
            'D' => 'failed',
            'V' => 'reversed',
            default => 'unknown',
        };
    }
}
