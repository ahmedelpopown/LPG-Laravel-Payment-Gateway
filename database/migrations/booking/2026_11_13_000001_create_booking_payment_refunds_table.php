<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_payment_id')->constrained('booking_payments')->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_refund_id', 120)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('reason', 255)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('requested_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_refund_id'],
                'booking_payment_refunds_provider_reference_unique',
            );
            $table->index(
                ['booking_payment_id', 'status'],
                'booking_payment_refunds_payment_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_refunds');
    }
};
