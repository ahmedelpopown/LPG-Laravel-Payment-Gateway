<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('gateway', 50)->index();
            $table->string('status', 40)->index();
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            $table->unsignedInteger('attempt')->default(1);
            $table->json('summary')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'period_from', 'period_to'], 'payment_recon_gateway_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_runs');
    }
};
