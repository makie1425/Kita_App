<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('batch_number_history')) {
            Schema::create('batch_number_history', function (Blueprint $table) {
                $table->unsignedBigInteger('inventoryBatchId')->primary();
                $table->string('previousNumber', 255)->nullable();
                $table->string('batchNumber', 80);
                $table->timestamp('created_at');
            });
        }
        \App\Services\StandardizeLegacyBatches::run();
    }

    public function down(): void
    {
        // Published batch references and their traceability must survive rollback.
    }
};
