<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            // Optional polymorphic owner. IDs are strings to support integer, UUID and ULID keys.
            $table->string('payable_type', 191)->nullable();
            $table->string('payable_id', 191)->nullable();

            $table->string('provider', 20);
            $table->string('provider_reference', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->string('idempotency_key', 100)->unique();

            // Major currency units are stored as fixed precision decimals, never floats.
            $table->decimal('amount', 18, 4);
            $table->decimal('refunded_amount', 18, 4)->default(0);
            $table->char('currency', 3);
            $table->string('status', 30)->default('pending');

            $table->text('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_reference'], 'payments_provider_reference_unique');
            $table->unique(['provider', 'provider_transaction_id'], 'payments_provider_transaction_unique');
            $table->index(['payable_type', 'payable_id'], 'payments_payable_index');
            $table->index(['provider', 'status'], 'payments_provider_status_index');
            $table->index(['created_at', 'provider'], 'payments_created_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
