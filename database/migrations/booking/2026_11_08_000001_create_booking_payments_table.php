<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();

            $table->string('provider', 20);
            $table->string('provider_reference', 120)->nullable();
            $table->string('provider_transaction_id', 120)->nullable();
            $table->string('idempotency_key', 64)->unique();

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 30)->default('pending');

            $table->text('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['provider', 'provider_reference'],
                'booking_payments_provider_reference_unique'
            );

            $table->unique(
                ['provider', 'provider_transaction_id'],
                'booking_payments_provider_transaction_unique'
            );

            $table->index(
                ['booking_id', 'status'],
                'booking_payments_booking_status_index'
            );

            $table->index(
                ['provider', 'status'],
                'booking_payments_provider_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
