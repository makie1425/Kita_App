<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('receiving_deliveries', function (Blueprint $t) {
            $t->string('batchNumber', 80)->primary();
            $t->uuid('requestKey')->unique();
            $t->string('payloadHash', 64);
            $t->unsignedBigInteger('supplierId');
            $t->date('receivedDate');
            $t->unsignedBigInteger('receivedBy');
            $t->text('receipts');
            $t->timestamp('created_at');
        });
        Schema::table('receiving_record_lines', fn (Blueprint $t) => $t->text('followupNote')->nullable());
        Schema::table('receiving_record_lines', fn (Blueprint $t) => $t->dropUnique(['internalBatch']));
        Schema::table('receiving_records', fn (Blueprint $t) => $t->string('deliveryBatch', 80)->nullable()->index());
    }
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('receiving_record_lines')->whereNotNull('internalBatch')->groupBy('internalBatch')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new \RuntimeException('Shared deliveries exist. Preserve their batch references before reverting this migration.');
        }
        Schema::table('receiving_record_lines', fn (Blueprint $t) => $t->unique('internalBatch'));
        Schema::table('receiving_records', function (Blueprint $t) { $t->dropIndex(['deliveryBatch']); $t->dropColumn('deliveryBatch'); });
        Schema::table('receiving_record_lines', fn (Blueprint $t) => $t->dropColumn('followupNote'));
        Schema::dropIfExists('receiving_deliveries');
    }
};
