<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('reconciliation_run_id')->constrained('payment_reconciliation_runs')->cascadeOnDelete();
            // Deliberately no FK: consuming applications may use integer, UUID or ULID payment keys.
            $table->string('local_payment_id', 100)->nullable()->index();
            $table->string('provider_reference', 191)->nullable()->index();
            $table->string('result', 50)->index();
            $table->string('local_status', 60)->nullable();
            $table->string('provider_status', 60)->nullable();
            $table->bigInteger('local_amount_minor')->nullable();
            $table->bigInteger('provider_amount_minor')->nullable();
            $table->bigInteger('local_refunded_amount_minor')->nullable();
            $table->bigInteger('provider_refunded_amount_minor')->nullable();
            $table->char('local_currency', 3)->nullable();
            $table->char('provider_currency', 3)->nullable();
            $table->json('differences')->nullable();
            $table->json('provider_snapshot')->nullable();
            $table->string('resolution_status', 30)->default('unresolved')->index();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['reconciliation_run_id', 'result'], 'payment_recon_run_result_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_items');
    }
};
