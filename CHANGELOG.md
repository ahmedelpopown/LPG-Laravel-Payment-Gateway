## 2.0.3 (test candidate)

- Added safe Core Payment cancellation for Stripe Checkout. The package expires the remote Checkout Session and marks the local payment cancelled only after Stripe confirms an expired, unpaid session.
- Reconciles a lost Stripe expiry response by retrieving the Checkout Session before changing local state.
- Keeps completed-but-unpaid asynchronous Checkout Sessions in processing and never unlocks another provider attempt prematurely.
- Allows `Cancelled` as a provider-confirmed finalization result.

# Changelog

## 2.0.2 - PayPal recovery and package test harness

### Audit follow-up fixes
- PayPal checkout `invoice_id` is now derived from the persisted payment idempotency key, so auto-increment ID reuse cannot collide with a previous Sandbox/Live invoice.
- PayPal, Stripe payment, and Stripe refund webhook processors now return a retryable HTTP 503 for duplicate deliveries whose event is still processing or awaiting retry. Only terminal duplicate events are acknowledged with HTTP 200.


### Added
- PayPal `showOrder()` support to retrieve the authoritative order and capture details.
- PayPal availability check that forces a fresh OAuth request instead of trusting a cached token.
- Capture recovery: after an ambiguous capture timeout, transient provider response, or `ORDER_ALREADY_CAPTURED`, retrieve the PayPal order and reuse its existing capture when available.
- Capture settlement metadata for PayPal gross amount, fee, net amount, and seller receivable breakdown when returned by PayPal.
- Standalone Pest/Orchestra Testbench setup and package-level PayPal/Core installation tests.
- A repeatable release test checklist and preserved Barber House integration tests.

### Fixed
- PayPal network failures are distinguished from HTTP/API authentication failures. Only Laravel HTTP `ConnectionException` is classified as a network connection error.
- PayPal `ORDER_ALREADY_CAPTURED` is extracted from `details[*].issue` when the top-level response is `UNPROCESSABLE_ENTITY`.
- Unknown PayPal capture states remain `processing` instead of being marked `failed` without authoritative evidence.
- Browser callback confirmation failures no longer become unhandled 500 responses. The payment state is preserved and the response warns the customer not to pay again while the provider outcome is checked.

### Preserved
- Generic Payment Core, Stripe Checkout/PaymentIntent integrations, refund ledger, Stripe refund webhooks, PayPal webhooks, and reconciliation from 2.0.0/2.0.1.
- Optional Booking and Stancl Tenancy integrations remain opt-in.

### Verification status
- PHP syntax checks and static package structure checks are run when building this archive.
- The Composer/Pest suite must be run after installing development dependencies. A real Stripe/PayPal Sandbox round trip remains a separate integration test and is not implied by unit tests.

## 2.0.1 - Standalone installation hotfix

### Fixed
- Booking migrations are installed only when the Booking integration is explicitly enabled with `--with-booking` or `payments.booking.enabled=true`; legacy route auto-registration flags no longer trigger database migrations.
- Booking Blade views and anonymous components are loaded only when Booking integration is enabled and Livewire is installed, preventing standalone `view:cache` failures from host-application UI components.

## 2.0.0 - Standalone Payment Core refactor

### Added
- Provider-agnostic `Payment` and `PaymentRefund` models and actions.
- Generic Stripe and PayPal Checkout, capture/confirmation, refund and webhook flows.
- Core webhook-event storage associated with generic payments.
- Generic reconciliation defaults for the package `Payment` model.
- Kept a nullable, non-constrained legacy `booking_payment_id` reference in the webhook ledger for v1.x upgrade compatibility without requiring a Booking table.
- Livewire is now suggested rather than required; the standalone Payment Core does not depend on it.
- Optional polymorphic payable and refund requester relations.
- Local Composer path-repository workflow and a standalone Playground test guide.

### Changed
- `payments:install` installs Payment Core and Reconciliation by default.
- Booking migrations, including the legacy webhook-to-booking link, are opt-in with `--with-booking` or explicit Booking configuration.
- Stancl Tenancy credential migration is run only when Stancl Tenancy is configured.
- Core routes initialize Stancl tenancy by domain when Tenancy is configured.
- Browser cancellation redirects no longer mark a payment cancelled; only verified provider updates may change the canonical payment state.

### Fixed
- Shortened the reconciliation resolution foreign-key name for MySQL identifier limits.
- Added provider idempotency keys per refund request for Stripe and PayPal.
- Added duplicate webhook-event claiming and retryable event states.
- Corrected generic reconciliation reference/status mappings.
- Preserved retryability for payment creation when provider credentials are not yet configured.

### Upgrade notes
- Back up the database before changing package versions.
- Existing Booking tables and models are retained as an optional legacy integration. Run `payments:install --with-booking` only in an app that has the required `bookings` and `employees` tables.
- The standalone Core creates `payments`, `payment_refunds`, and `payment_webhook_events`, plus the three Reconciliation tables. It does not migrate or rename existing `booking_payments` records.
- Do not run `migrate:fresh` against an existing project database.
"""
PY
cat > RELEASE-TEST-CHECKLIST.md <<'MD'
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
