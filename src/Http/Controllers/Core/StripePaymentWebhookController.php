<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Core;

use Ahmed\LaravelPayment\Core\Webhooks\ProcessStripePaymentWebhook;
use Ahmed\LaravelPayment\Core\Webhooks\ProcessStripeRefundWebhook;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Payments\Stripe\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

final class StripePaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        StripeWebhookVerifier $verifier,
        ProcessStripePaymentWebhook $paymentProcessor,
        ProcessStripeRefundWebhook $refundProcessor,
    ): JsonResponse {
        try {
            $event = $verifier->verify($request->getContent(), (string) $request->header('Stripe-Signature', ''));
            if ($event === null) {
                return response()->json(['message' => 'Invalid Stripe webhook signature or payload.'], 400);
            }

            $eventType = (string) ($event['type'] ?? '');
            $refundEvents = [
                'refund.created',
                'refund.updated',
                'refund.failed',
                'charge.refund.updated',
                'charge.refunded',
            ];

            $result = in_array($eventType, $refundEvents, true)
                ? $refundProcessor->handle($event)
                : $paymentProcessor->handle($event);

            return response()->json(['received' => true, 'result' => $result]);
        } catch (PaymentGatewayException $exception) {
            report($exception);
            $permanent = preg_match('/(invalid|mismatch|missing|unsupported|exceeds)/i', (string) $exception->providerCode) === 1;

            return response()->json(
                ['message' => $permanent ? 'Invalid Stripe webhook event.' : 'Stripe webhook processing is temporarily unavailable.'],
                $permanent ? 400 : 503,
            );
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Stripe webhook processing is temporarily unavailable.'], 503);
        }
    }
}
