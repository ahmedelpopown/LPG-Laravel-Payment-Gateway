<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Payments;

use Ahmed\LaravelPayment\Actions\Payments\ProcessPayPalWebhook;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Routing\Controller;
use Ahmed\LaravelPayment\Payments\PayPal\PayPalWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class PayPalWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PayPalWebhookVerifier $webhookVerifier,
        ProcessPayPalWebhook $processPayPalWebhook,
    ): JsonResponse {
        $rawEventBody = $request->getContent();

        try {
            $event = json_decode($rawEventBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['message' => 'Invalid PayPal webhook payload.'], 400);
        }

        if (! is_array($event)
            || ! is_string($event['id'] ?? null)
            || ! is_string($event['event_type'] ?? null)) {
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
            if (! $webhookVerifier->verify($headers, $rawEventBody)) {
                return response()->json(['message' => 'PayPal webhook signature is invalid.'], 400);
            }

            $result = $processPayPalWebhook->handle($event);

            return response()->json([
                'received' => true,
                'result' => $result,
            ], 200);
        } catch (PaymentGatewayException $exception) {
            report($exception);

            if ($exception->providerCode === 'paypal_webhook_event_invalid') {
                return response()->json(['message' => 'Invalid PayPal webhook event.'], 400);
            }

            // A non-2xx response asks PayPal to retry temporary failures.
            return response()->json(['message' => 'PayPal webhook processing is temporarily unavailable.'], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'PayPal webhook processing is temporarily unavailable.'], 503);
        }
    }
}
