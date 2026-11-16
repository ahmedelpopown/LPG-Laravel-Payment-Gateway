# Stripe Refund Webhook Synchronization

The Core Stripe webhook endpoint processes Checkout and Refund lifecycle events through separate handlers.

## Supported events

- `refund.created`
- `refund.updated`
- `refund.failed`
- `charge.refund.updated` (Stripe emits this for selected payment methods)
- `charge.refunded`

## Local listener

Run the application and forward these events from Stripe CLI:

```powershell
stripe listen --events=checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,refund.created,refund.updated,refund.failed,charge.refund.updated,charge.refunded --forward-to http://127.0.0.1:8000/payments/webhooks/stripe
```

Put the `whsec_...` value printed by this listener in `STRIPE_WEBHOOK_SECRET`, then clear the Laravel config cache:

```powershell
php artisan config:clear
```

## Synchronization behavior

1. Verify the untouched request body and Stripe signature.
2. Claim the event using the unique `(provider, event_id)` webhook ledger key.
3. Match a Refund to a Core Payment using its PaymentIntent ID. If the Checkout success event has not yet populated the local transaction ID, the processor retrieves the PaymentIntent and uses the package-owned `payment_id` metadata only after validating amount, currency, and captured status.
4. Match an existing local Refund by Stripe Refund ID or the refund idempotency key carried in Stripe Refund metadata. Dashboard-created refunds are inserted using a deterministic local key derived from the Stripe Refund ID.
5. Recalculate `payments.refunded_amount` from completed Refund ledger rows only. Pending/processing refunds reserve refundable balance but are not counted as completed money returned.
6. Treat duplicate event IDs as duplicates. Different Stripe events referring to the same Stripe Refund update the same local row because `provider_refund_id` is unique per provider.
7. For `charge.refunded`, retrieve all pages of Refunds for that Charge when the embedded Charge refund collection is incomplete.

## Manual verification

After a successful Stripe Test Mode payment and one or more refunds, inspect:

```php
use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Core\Models\PaymentRefund;
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;

$payment = Payment::query()->whereKey(12)->firstOrFail();
$payment->refresh();

$payment->only(['id', 'status', 'amount', 'refunded_amount', 'refunded_at']);

PaymentRefund::query()
    ->where('payment_id', $payment->getKey())
    ->orderBy('id')
    ->get(['id', 'provider_refund_id', 'amount', 'currency', 'status']);

PaymentWebhookEvent::query()
    ->where('provider', 'stripe')
    ->latest('id')
    ->limit(10)
    ->get(['event_id', 'event_type', 'payment_id', 'status', 'error_code', 'processed_at']);
```

For a partial refund of 3.00 on a 10.00 payment, the completed refund total should be 3.00 and the Payment status should be `partially_refunded`. After refunding the remaining 7.00, the completed refund total should be 10.00 and the Payment status should be `refunded`.

Do not use Stripe Dashboard to create a refund and then also issue a second refund from the application for the same amount. They are separate refund requests unless you are specifically testing external-refund synchronization.

## Verification boundary

The source package was syntax-checked after this change. A live Laravel/MySQL/Stripe integration run is still required in the consumer Playground before publishing a release tag.

## Terminal-state protection

A locally completed or failed Refund is treated as terminal. A later-delivered
Stripe event cannot flip `completed` to `failed` or `failed` to `completed`.
Stripe retries that create a new refund must use a new Stripe Refund ID; that is
a separate ledger row. Older events are recorded in the webhook event ledger
but do not overwrite the latest accepted Refund snapshot.

## Manual idempotency checks

Replay the exact same successful Refund request with its original
`idempotency_key`. The returned local `payment_refunds.id` and
`provider_refund_id` must remain unchanged, and the count of Refund rows for the
Payment must not increase. Do not generate a new key when testing replay.
