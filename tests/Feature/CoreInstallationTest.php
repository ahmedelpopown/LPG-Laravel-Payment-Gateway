<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('installs the generic payment and reconciliation tables without Booking integration', function (): void {
    Artisan::call('migrate', ['--database' => 'testing', '--force' => true]);

    expect(Schema::connection('testing')->hasTable('payments'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_refunds'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_webhook_events'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_reconciliation_runs'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_reconciliation_items'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('payment_reconciliation_resolutions'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('booking_payments'))->toBeFalse();
});
