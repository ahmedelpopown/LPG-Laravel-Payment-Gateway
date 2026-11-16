# Standalone Laravel Playground: Phase 1

This guide tests the generic Payment Core in a normal Laravel application. It does not use Stancl Tenancy, a central database, tenant databases, bookings, or employees.

## Expected environment

- PHP 8.3 or newer
- Laravel 11, 12, or 13
- MySQL 8+ or a supported MariaDB version
- A disposable database dedicated to this test
- Stripe test-mode credentials for the Stripe flow
- PayPal Sandbox credentials for the PayPal flow

## 1. Install the package from the sibling source folder

Expected folder layout:

```text
barber-v6-clean-with-payment-package/
├── laravel-payment/
└── payment-package-playground/
```

In `payment-package-playground/composer.json`, add this local repository entry:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-payment",
            "options": {
                "symlink": true,
                "versions": {
                    "ahmed/laravel-payment": "2.0.2"
                }
            }
        }
    ]
}
```

From the Playground root:

```powershell
composer require ahmed/laravel-payment:^2.0 -W
```

The path repository is for local development only. It avoids stale Git tags while the package is being changed. Do not run this `composer require` command inside the `laravel-payment` package folder.

## 2. Configure a disposable database

Create a database named `payment_package_test`, then configure `.env`:

```dotenv
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=payment_package_test
DB_USERNAME=root
DB_PASSWORD=

STRIPE_SECRET=sk_test_replace_me
STRIPE_WEBHOOK_SECRET=whsec_replace_me

PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=replace_me
PAYPAL_CLIENT_SECRET=replace_me
PAYPAL_WEBHOOK_ID=replace_me
```

Do not put live credentials in the Playground. Keep `.env` out of Git.

Publish configuration:

```powershell
php artisan key:generate
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=laravel-payment-reconciliation-config
php artisan optimize:clear
```

Before installation, make sure the published `config/payments.php` has Booking disabled:

```php
'booking' => [
    'enabled' => false,
    // keep the other defaults from the package config
],
```

If this config file already existed, `vendor:publish` may have skipped it. Do not use `--force` just to change this setting because that can overwrite credentials and project-specific values. The current installer does not treat legacy `routes.auto_register` values as consent to install Booking database tables.

## 3. Install the Payment Core

```powershell
php artisan payments:install
```

For standalone Laravel, the installer should migrate the Core and Reconciliation tables on the default DB connection. It must not attempt Booking or Tenancy migrations.

Expected package tables:

- `payments`
- `payment_refunds`
- `payment_webhook_events`
- `payment_reconciliation_runs`
- `payment_reconciliation_items`
- `payment_reconciliation_resolutions`

Verify:

```powershell
php artisan migrate:status
php artisan route:list --path=payments
```

There should be no package-created `bookings`, `employees`, `booking_payments`, or `booking_payment_refunds` tables in the standalone installation.

## 4. Add a local-only Stripe checkout test route

Create `app/Http/Controllers/PlaygroundStripePaymentController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Ahmed\LaravelPayment\Core\Actions\CreatePayment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PlaygroundStripePaymentController
{
    public function __invoke(Request $request, CreatePayment $createPayment): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $payment = $createPayment->handle(
            provider: PaymentProvider::Stripe,
            amount: (string) $data['amount'],
            currency: strtoupper($data['currency']),
            idempotencyKey: (string) Str::uuid(),
            metadata: ['source' => 'standalone-playground'],
        );

        return redirect()->away((string) $payment->checkout_url);
    }
}
```

Add to `routes/web.php`:

```php
use App\Http\Controllers\PlaygroundStripePaymentController;
use Illuminate\Support\Facades\Route;

Route::post('/_playground/payments/stripe', PlaygroundStripePaymentController::class);
```

Use a local Blade form or an API client to POST `amount=12.50` and `currency=USD`. The route refuses to run outside local/testing environments. Remove it before deployment.

The browser should redirect to Stripe Checkout. Complete the payment with a Stripe test card from Stripe's official testing documentation. After returning, inspect the record:

```powershell
php artisan tinker
```

```php
use Ahmed\LaravelPayment\Core\Models\Payment;

Payment::query()->latest()->first();
```

## 5. Test Stripe webhooks

Run the local app:

```powershell
php artisan serve
```

Forward Stripe test events to:

```text
http://127.0.0.1:8000/payments/webhooks/stripe
```

Use Stripe CLI or a configured Stripe test-mode webhook endpoint. Set the webhook signing secret returned by the listener in `STRIPE_WEBHOOK_SECRET`, then run `php artisan optimize:clear`.

A successful flow should:

1. Create a local `payments` row with `pending` status.
2. Save the Checkout Session ID as `provider_reference`.
3. Verify the webhook signature.
4. Retrieve and verify the Checkout Session against local amount, currency, and payment reference.
5. Save the PaymentIntent ID as `provider_transaction_id` and mark the payment `paid`.
6. Record the event once in `payment_webhook_events`; a repeated event ID must not process twice.

## 6. Test refunds

Only run this after a successful test-mode payment. In Tinker:

```php
use Ahmed\LaravelPayment\Core\Actions\RefundPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Illuminate\Support\Str;

$payment = Payment::query()->where('status', 'paid')->latest()->firstOrFail();

$refund = app(RefundPayment::class)->handle(
    payment: $payment,
    amount: '2.50',
    idempotencyKey: (string) Str::uuid(),
    reason: 'Standalone Playground test',
);

$refund;
$payment->fresh();
```

Use a new idempotency key for a new refund request. Reuse the same key only to retry the exact same logical refund after an ambiguous provider response.

## 7. Test PayPal Sandbox

Configure the PayPal Sandbox client ID, secret, mode, and webhook ID in `.env`, then run:

```powershell
php artisan optimize:clear
```

Create a second local-only controller/route using `PaymentProvider::PayPal` in place of `PaymentProvider::Stripe`. Use a PayPal Sandbox buyer account to approve the order. Configure the PayPal Sandbox webhook to:

```text
https://your-public-test-url/payments/webhooks/paypal
```

A local `127.0.0.1` URL is not publicly reachable by PayPal; use a secure tunnel for webhook testing. The webhook ID in `.env` must be the ID of the Sandbox webhook endpoint, not a Live webhook.

## 8. Test Reconciliation

After at least one provider payment exists, run a bounded date range:

```powershell
php artisan payment:reconcile stripe --from="2026-11-14T00:00:00+00:00" --to="2026-11-15T00:00:00+00:00"
```

For PayPal:

```powershell
php artisan payment:reconcile paypal --from="2026-11-14T00:00:00+00:00" --to="2026-11-15T00:00:00+00:00"
```

Review the `payment_reconciliation_runs` and `payment_reconciliation_items` rows. A run with provider errors is not proof that the local payment is missing; inspect the run status and error details.

## Safety and reset rules

- Use only the disposable `payment_package_test` database.
- `migrate:fresh` deletes every table in the selected database. Use it only when you are certain the connection points to this disposable database.
- Never run `migrate:fresh` against Barber House, a production database, or a database containing real payment records.
- Use only Stripe Test Mode and PayPal Sandbox credentials.
- Keep test routes local/testing-only and remove them before deployment.
- This guide is a manual integration test plan. It does not replace automated tests for concurrency, retries, provider error responses, and webhook delivery.

### Stripe Refund webhook events

For local testing, forward these events to the Core Stripe endpoint:

```powershell
stripe listen --events=checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,refund.created,refund.updated,refund.failed,charge.refund.updated,charge.refunded --forward-to http://127.0.0.1:8000/payments/webhooks/stripe
```

The Core endpoint handles Checkout events and Refund events through separate processors. Refunds are matched by Stripe PaymentIntent and Stripe Refund IDs; `charge.refunded` retrieves all pages of Refunds for the Charge to avoid relying only on the limited embedded Charge refund collection.
