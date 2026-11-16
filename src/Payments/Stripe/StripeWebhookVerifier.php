<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Payments\Stripe;

use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

final class StripeWebhookVerifier
{
    public function __construct(
        private readonly StripeCredentialsResolver $credentialsResolver,
    ) {}

    /**
     * Verify the untouched request body against Stripe-Signature.
     * Returns the verified event as an array, or null when the signature/payload is invalid.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $rawEventBody, string $signatureHeader): ?array
    {
        if (trim($rawEventBody) === '' || trim($signatureHeader) === '') {
            return null;
        }

        $credentials = $this->credentialsResolver->resolve(requireWebhookSecret: true);

        try {
            $event = Webhook::constructEvent(
                $rawEventBody,
                $signatureHeader,
                (string) $credentials->webhookSecret,
            );

            return $event->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return null;
        } catch (Throwable $exception) {
            throw new PaymentGatewayException(
                'Stripe webhook verification could not be completed.',
                'stripe_webhook_verification_error',
                null,
                $exception,
            );
        }
    }
}
