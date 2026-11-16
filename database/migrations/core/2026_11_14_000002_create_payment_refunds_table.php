<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->string('provider', 20);
            $table->string('provider_refund_id', 191)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('reason', 255)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();

            // Optional requester, without coupling the package to an Employee/User table.
            $table->string('requested_by_type', 191)->nullable();
            $table->string('requested_by_id', 191)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_refund_id'], 'payment_refunds_provider_reference_unique');
            $table->index(['payment_id', 'status'], 'payment_refunds_payment_status_index');
            $table->index(['requested_by_type', 'requested_by_id'], 'payment_refunds_requester_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
