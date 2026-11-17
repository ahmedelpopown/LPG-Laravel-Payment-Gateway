# Payment package audit status

This folder is a **2.0.2 release candidate**, not a certified release. The repository snapshot supplied for review contains Git tag `v1.0.0`, while `VERSION` and `CHANGELOG.md` say `2.0.2`. Do not publish/tag 2.0.2 until the Composer/Pest suite and provider Sandbox tests have passed.

## Changes included in this candidate

- Stable PayPal `invoice_id` derived from the persisted payment idempotency key.
- Duplicate Webhook deliveries in `processing`/`received` state are answered with a retryable 503 rather than an unsafe 200 acknowledgement. Terminal duplicates remain acknowledged with 200.
- Mini Store registers `PaymentObserver` so successful verified Payment updates sync to the associated Order and attempt.
- Mini Store blocks shipping/completion when a payment is unconfirmed or fully refunded.
- Mini Store now uses the real package name and a sibling path repository at `../laravel-payment`.
- Added focused tests for PayPal invoice ID uniqueness and in-flight Webhook retries, plus a Mini Store PaymentObserver feature test.

## Known limitations still requiring work

- PayPal `PAYMENT.CAPTURE.REFUNDED` and `PAYMENT.CAPTURE.REVERSED` are not yet reconciled into the generic Payment/PaymentRefund ledger by the current PayPal Webhook processor. A PayPal refund that remains pending needs an explicit verified webhook/reconciliation path before this package can claim complete PayPal refund-event coverage.
- The package test suite is still small relative to the requested high-volume test campaign. Stripe Core, refund state transitions, database concurrency, Booking opt-in, and full reconciliation need dedicated automated coverage.
- `composer.lock` in Mini Store still reflects the previously installed remote `v1.0.0` source. Regenerate it after installing Composer by running the update command in the Mini Store README.
- This build environment has no Composer executable, PHPUnit/Pest executable, or PDO database drivers. Only PHP syntax and JSON structure can be checked here; do not interpret that as tests passing.

## Required commands after extracting sibling folders

Run from `mini-store`:

```powershell
composer update ahmedelpopown/lpg-laravel-payment-gateway -W
composer validate --strict
composer install
php artisan optimize:clear
php artisan migrate
php artisan test
```

Run package tests from `laravel-payment`:

```powershell
composer validate --strict
composer install
composer test
```

Use a disposable database and Sandbox/Test credentials only. Do not run `migrate:fresh` against valuable data.


## Stripe Checkout cancellation candidate

- Added `CancellableCheckoutGateway` and Core `CancelPayment` action.
- Added Stripe Checkout Session expiry support through the Stripe SDK.
- Local cancellation is finalized only after Stripe confirms the Session is expired and unpaid.
- Completed-but-unpaid asynchronous Sessions remain `processing`; paid Sessions remain `paid`.
- Ambiguous expiry/network outcomes leave the payment open for reconciliation.
- `FinalizePayment` accepts provider-confirmed `Cancelled` results and does not allow later non-paid events to reopen a cancelled payment.
- Added unit/feature tests for the cancellation flow. These tests were not executed in the artifact-building environment because Composer/Pest dependencies were unavailable; PHP syntax checks passed.
