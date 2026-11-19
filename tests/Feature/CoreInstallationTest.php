<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('installs the Payment Core and Reconciliation tables without legacy integrations', function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);

    expect(Schema::connection('testing')->hasTable('payments'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_refunds'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_webhook_events'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_reconciliation_runs'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_reconciliation_items'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_reconciliation_resolutions'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('booking_payments'))->toBeFalse()
        ->and(Schema::connection('testing')->hasTable('tenant_payment_credentials'))->toBeFalse();
});
