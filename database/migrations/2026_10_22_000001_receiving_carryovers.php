<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->unsignedInteger('carryoverQty')->default(0);
            $t->unsignedInteger('resolvedQty')->default(0);
        });
        Schema::create('receiving_carryovers', function (Blueprint $t) {
            $t->id();
            $t->uuid('requestKey')->unique();
            $t->string('sourcePo')->index();
            $t->unsignedBigInteger('sourceLine')->index();
            $t->string('targetPo')->index();
            $t->unsignedBigInteger('targetLine')->index();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('receivedQty')->default(0);
            $t->unsignedInteger('releasedQty')->default(0);
            $t->unsignedBigInteger('actorId');
            $t->timestamp('created_at');
        });
        Schema::create('receiving_carryover_resolutions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carryoverId')->constrained('receiving_carryovers');
            $t->string('receiptId')->index();
            $t->unsignedInteger('quantity');
            $t->decimal('unitCost', 12, 2);
            $t->timestamp('created_at');
        });
        Schema::create('receiving_inspections', function (Blueprint $t) {
            $t->id();
            $t->string('receiptId')->index();
            $t->string('poId')->index();
            $t->unsignedBigInteger('orderLine')->index();
            $t->unsignedBigInteger('productId');
            $t->unsignedInteger('accepted');
            $t->unsignedInteger('damaged');
            $t->unsignedInteger('missing');
            $t->unsignedInteger('newMissing');
            $t->string('damageReason', 500)->nullable();
            $t->timestamp('created_at');
            $t->unique(['receiptId', 'orderLine']);
        });
        Schema::table('receiving_record_lines', function (Blueprint $t) {
            $t->string('internalBatch', 80)->nullable()->unique();
        });
    }
    public function down(): void
    {
        Schema::table('receiving_record_lines', fn (Blueprint $t) => $t->dropColumn('internalBatch'));
        Schema::dropIfExists('receiving_inspections');
        Schema::dropIfExists('receiving_carryover_resolutions');
        Schema::dropIfExists('receiving_carryovers');
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn(['carryoverQty','resolvedQty']));
    }
};
