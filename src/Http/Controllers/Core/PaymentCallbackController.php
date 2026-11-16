<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Core;

use Ahmed\LaravelPayment\Core\Actions\ConfirmPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

final class PaymentCallbackController extends Controller
{
    public function complete(string $provider, Payment $payment, Request $request, ConfirmPayment $confirm): JsonResponse|RedirectResponse
    {
        abort_unless($payment->provider === PaymentProvider::tryFrom($provider), 404);

        if ($provider === PaymentProvider::Stripe->value) {
            $sessionId = $request->query('session_id');
            abort_unless(is_string($sessionId) && hash_equals((string) $payment->provider_reference, $sessionId), 400);
        } elseif ($provider === PaymentProvider::PayPal->value) {
            $orderId = $request->query('token');
            abort_unless(is_string($orderId) && hash_equals((string) $payment->provider_reference, $orderId), 400);
        }

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
            try {
                $payment = $confirm->handle($payment);
            } catch (PaymentGatewayException $exception) {
                Log::warning('Payment callback confirmation could not be completed.', [
                    'payment_id' => $payment->public_id,
                    'provider' => $provider,
                    'provider_code' => $exception->providerCode,
                    'http_status' => $exception->httpStatus,
                ]);

                // A browser return is not a failed payment signal. In
                // particular, a network timeout can happen after PayPal has
                // already captured the money. Preserve the current local
                // status and let the customer refresh/reconcile this payment.
                $payment->refresh();
                $warning = 'We could not confirm the payment with the provider yet. Do not create or pay for another order. Your payment status can be checked again shortly.';
                $successUrl = config('payments.redirects.success_url');

                if (is_string($successUrl) && trim($successUrl) !== '') {
                    return redirect()->to($successUrl)->with([
                        'payment_warning' => $warning,
                        'payment_public_id' => $payment->public_id,
                    ]);
                }

                $temporary = $exception->providerCode === 'paypal_connection_error'
                    || $exception->providerCode === 'paypal_capture_outcome_unknown'
                    || in_array($exception->httpStatus, [408, 429, 500, 502, 503, 504], true);

                return response()->json([
                    'payment_id' => $payment->public_id,
                    'status' => $payment->status->value,
                    'message' => $warning,
                ], $temporary ? 503 : 422);
            }
        }

        $successUrl = config('payments.redirects.success_url');
        if (is_string($successUrl) && trim($successUrl) !== '') {
            return redirect()->to($successUrl);
        }

        return response()->json([
            'payment_id' => $payment->public_id,
            'status' => $payment->status->value,
            'message' => $payment->status === PaymentStatus::Paid ? 'Payment confirmed.' : 'Payment status updated.',
        ]);
    }

    public function cancel(string $provider, Payment $payment): JsonResponse|RedirectResponse
    {
        abort_unless($payment->provider === PaymentProvider::tryFrom($provider), 404);

        // A browser redirect is not authoritative evidence that the provider
        // cancelled or reversed a payment. Keep the payment state unchanged;
        // verified provider webhooks or a reconciliation run update it.
        $cancelUrl = config('payments.redirects.cancel_url');
        if (is_string($cancelUrl) && trim($cancelUrl) !== '') {
            return redirect()->to($cancelUrl);
        }

        return response()->json([
            'payment_id' => $payment->public_id,
            'status' => $payment->status->value,
            'message' => 'Checkout was cancelled or left before completion. The payment state remains unchanged until the provider confirms its final state.',
        ]);
    }
}
