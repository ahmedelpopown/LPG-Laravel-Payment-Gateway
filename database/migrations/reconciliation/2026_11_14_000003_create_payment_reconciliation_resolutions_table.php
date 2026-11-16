
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_resolutions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('reconciliation_item_id');

            $table->string('resolution', 120);
            $table->text('notes')->nullable();
            $table->string('resolved_by', 100)->nullable();

            $table->timestamps();

            $table->foreign('reconciliation_item_id', 'prr_item_fk')
                ->references('id')
                ->on('payment_reconciliation_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_resolutions');
    }
};
