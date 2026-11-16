# Release Test Checklist

Use a disposable database and dedicated Stripe test / PayPal Sandbox credentials. Do not use production credentials or a production database.

## 1. Clean installation

- [ ] `composer validate --strict`
- [ ] `composer install`
- [ ] `composer test`
- [ ] Install into a fresh Laravel 12 app with `php artisan payments:install`.
- [ ] Confirm that `payments`, `payment_refunds`, `payment_webhook_events`, and the three reconciliation tables exist.
- [ ] Confirm that Booking tables are not created unless Booking integration is explicitly enabled.
- [ ] Run `php artisan optimize:clear`, `php artisan route:list --path=payments`, and `php artisan view:cache`.
    
## 2. Core payment lifecycle

- [ ] Create Stripe and PayPal payments with server-owned amount/currency values.
- [ ] Reuse the same idempotency key and confirm the same payment record is returned rather than creating duplicates.
- [ ] Reject an idempotency key reused with a different provider, amount, or currency.
- [ ] Confirm that provider amount/currency/reference mismatches never mark a payment as paid.
- [ ] Confirm that browser cancel redirects do not mark a payment cancelled.

## 3. PayPal Sandbox

- [ ] `PayPalGateway::checkAvailability()` performs a fresh OAuth request and reports invalid credentials as an HTTP/API error, not a connection timeout.
- [ ] Create a Sandbox order and verify that the approval URL uses HTTPS and a PayPal host.
- [ ] Capture an approved order and verify `COMPLETED`, Capture ID, amount, and currency.
- [ ] Simulate a timeout after capture succeeds; the next confirmation must use `GET /v2/checkout/orders/{id}` and recover the existing Capture without creating another payment.
- [ ] Simulate `UNPROCESSABLE_ENTITY` with `details[0].issue=ORDER_ALREADY_CAPTURED`; verify that the existing Capture is recovered.
- [ ] Simulate a timeout where the order is still `APPROVED` and no Capture exists; verify that local state is not falsely marked paid or failed.
- [ ] Simulate an unavailable order lookup after a timeout; verify that the payment remains unconfirmed and is marked for reconciliation rather than retried as a new payment.
- [ ] Verify seller receivable gross, PayPal fee, net amount, and currency metadata when those fields are returned by PayPal.
- [ ] Send duplicate PayPal webhook deliveries and confirm event claiming is idempotent.
- [ ] Test full and partial refunds, duplicate refund idempotency keys, and refund amounts over the remaining refundable balance.

## 4. Stripe test mode

- [ ] Test Checkout and/or PaymentIntent completion using Stripe test credentials.
- [ ] Verify webhook signatures; invalid signatures must be rejected.
- [ ] Deliver duplicate payment and refund webhook events.
- [ ] Test asynchronous payment success/failure where supported by the selected Checkout method.
- [ ] Test full and partial refunds, refund failure/pending states, and amount/currency mismatches.

## 5. Reconciliation and concurrency

- [ ] Reconcile a known matching payment and inspect the reconciliation item.
- [ ] Reconcile a provider-only transaction and a local-only payment.
- [ ] Resolve an item and confirm resolution does not silently mutate the payment record.
- [ ] Run concurrent duplicate webhook/capture/refund requests and verify there are no duplicate local transactions or over-refunds.

## 6. Consumer application regression

- [ ] Test the package in the actual consuming Laravel application, with its configured database connection, queue, session, tenancy, and route middleware.
- [ ] If using Booking integration, run the preserved tests in `integration-tests/barber-house/` inside the Barber House application test environment.
- [ ] Confirm logs never contain API secrets, OAuth tokens, card data, or webhook signing secrets.

Passing the package unit suite does not replace the provider Sandbox round trips, host-application integration tests, or a production readiness review.
