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

                $table->string('status', 20)->default('received');
                $table->string('error_code', 120)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'event_id'], 'payment_webhook_events_provider_event_unique');
                $table->index(['provider', 'status'], 'payment_webhook_events_provider_status_index');
            });

            return;
        }

        // Add the generic payment relation when upgrading an existing event ledger.
        if (! Schema::hasColumn('payment_webhook_events', 'payment_id')) {
            Schema::table('payment_webhook_events', function (Blueprint $table): void {
                $table->foreignId('payment_id')->nullable()
                    ->after('event_type')
                    ->constrained('payments')
                    ->nullOnDelete();
            });
        }

    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_webhook_events')
            || ! Schema::hasColumn('payment_webhook_events', 'payment_id')) {
            return;
        }

        // Keep the event ledger when rolling back. Only remove the generic Core relation.
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
    }
};
