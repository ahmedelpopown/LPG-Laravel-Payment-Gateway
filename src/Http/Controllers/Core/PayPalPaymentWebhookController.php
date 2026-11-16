<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Core;

use Ahmed\LaravelPayment\Core\Webhooks\ProcessPayPalPaymentWebhook;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JsonException;
use Throwable;

final class PayPalPaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PayPalWebhookVerifier $verifier, ProcessPayPalPaymentWebhook $processor): JsonResponse
    {
        $raw = $request->getContent();
        try {
            $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid PayPal webhook payload.'], 400);
        }

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['event_type'] ?? null)) {
            return response()->json(['message' => 'Invalid PayPal webhook payload.'], 400);
        }

        $headers = [
            'paypal-auth-algo' => (string) $request->header('paypal-auth-algo', ''),
            'paypal-cert-url' => (string) $request->header('paypal-cert-url', ''),
            'paypal-transmission-id' => (string) $request->header('paypal-transmission-id', ''),
            'paypal-transmission-sig' => (string) $request->header('paypal-transmission-sig', ''),
            'paypal-transmission-time' => (string) $request->header('paypal-transmission-time', ''),
        ];

        try {
            if (! $verifier->verify($headers, $raw)) {
                return response()->json(['message' => 'PayPal webhook signature is invalid.'], 400);
            }

            return response()->json(['received' => true, 'result' => $processor->handle($event)]);
        } catch (PaymentGatewayException $exception) {
            report($exception);
            $permanent = preg_match('/(invalid|mismatch|missing|unsupported)/i', (string) $exception->providerCode) === 1;
            return response()->json(
                ['message' => $permanent ? 'Invalid PayPal webhook event.' : 'PayPal webhook processing is temporarily unavailable.'],
                $permanent ? 400 : 503,
            );
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'PayPal webhook processing is temporarily unavailable.'], 503);
        }
    }
}
