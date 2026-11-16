<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_webhook_events')) {
            Schema::create('payment_webhook_events', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('provider', 20);
                $table->string('event_id', 191);
                $table->string('event_type', 150);
                $table->foreignId('payment_id')->nullable()
                    ->constrained('payments')
                    ->nullOnDelete();

                // Legacy compatibility only. There is deliberately no FK to a Booking table.
                $table->unsignedBigInteger('booking_payment_id')->nullable();

                $table->string('status', 20)->default('received');
                $table->string('error_code', 120)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'event_id'], 'payment_webhook_events_provider_event_unique');
                $table->index(['provider', 'status'], 'payment_webhook_events_provider_status_index');
                $table->index('booking_payment_id', 'payment_webhook_events_booking_payment_index');
            });

            return;
        }

        // Upgrade path for installations that already have the legacy Booking
        // webhook table from v1.x. Preserve its records and booking_payment_id.
        if (! Schema::hasColumn('payment_webhook_events', 'payment_id')) {
            Schema::table('payment_webhook_events', function (Blueprint $table): void {
                $table->foreignId('payment_id')->nullable()
                    ->after('event_type')
                    ->constrained('payments')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('payment_webhook_events', 'booking_payment_id')) {
            Schema::table('payment_webhook_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('booking_payment_id')->nullable();
                $table->index('booking_payment_id', 'payment_webhook_events_booking_payment_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_webhook_events')
            || ! Schema::hasColumn('payment_webhook_events', 'payment_id')) {
            return;
        }

        // Keep the event ledger and legacy Booking data when rolling back. Only
        // remove the generic Core relation introduced by this migration.
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
    }
};
