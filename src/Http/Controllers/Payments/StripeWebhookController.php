<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Payments;

use Ahmed\LaravelPayment\Actions\Payments\ProcessStripeWebhook;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Routing\Controller;
use Ahmed\LaravelPayment\Payments\Stripe\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class StripeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        StripeWebhookVerifier $webhookVerifier,
        ProcessStripeWebhook $processStripeWebhook,
    ): JsonResponse {
        try {
            $event = $webhookVerifier->verify(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );

            if ($event === null) {
                return response()->json(['message' => 'Invalid Stripe webhook signature or payload.'], 400);
            }

            $result = $processStripeWebhook->handle($event);

            return response()->json([
                'received' => true,
                'result' => $result,
            ], 200);
        } catch (PaymentGatewayException $exception) {
            report($exception);

            if (in_array($exception->providerCode, [
                'stripe_webhook_event_invalid',
                'stripe_webhook_session_invalid',
                'stripe_session_reference_mismatch',
                'stripe_session_metadata_mismatch',
                'stripe_session_amount_mismatch',
            ], true)) {
                return response()->json(['message' => 'Invalid Stripe webhook event.'], 400);
            }

            // Stripe retries events when the endpoint responds with a non-2xx status.
            return response()->json(['message' => 'Stripe webhook processing is temporarily unavailable.'], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Stripe webhook processing is temporarily unavailable.'], 503);
        }
    }
}
