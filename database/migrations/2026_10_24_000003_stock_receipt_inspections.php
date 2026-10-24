<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $t) {
            $t->string('id',40)->primary();
            $t->uuid('requestKey')->unique();
            $t->string('payloadHash',64);
            $t->unsignedBigInteger('supplierId');
            $t->date('deliveryDate');
            $t->string('status',30);
            $t->text('payload');
            $t->text('summary');
            $t->text('exceptions');
            $t->text('receipts')->nullable();
            $t->unsignedBigInteger('requestedBy');
            $t->unsignedBigInteger('reviewedBy')->nullable();
            $t->text('reviewNote')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('physical_inventory_lots', function (Blueprint $t) {
            $t->string('id',40)->primary();
            $t->unsignedBigInteger('productId')->index();
            $t->unsignedBigInteger('supplierId');
            $t->string('supplierLot',80)->nullable();
            $t->date('expiryDate')->nullable();
            $t->date('manufacturedDate')->nullable();
            $t->string('stockReceipt',40);
            $t->timestamp('created_at');
        });
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            // Null means older receipts have not classified the outstanding obligation.
            $t->unsignedInteger('damageOutstanding')->nullable();
        });
        Schema::table('receiving_record_lines', function (Blueprint $t) {
            $t->string('physicalLotId',40)->nullable()->index();
            $t->unsignedBigInteger('originalProductId')->nullable();
        });
        Schema::table('inventory_batches', fn (Blueprint $t) => $t->string('physicalLotId',40)->nullable()->index());
        Schema::create('receiving_obligation_events', function (Blueprint $t) {
            $t->id();
            $t->string('stockReceipt',40)->index();
            $t->unsignedBigInteger('orderLine')->index();
            $t->string('type',20);
            $t->unsignedInteger('delivered');
            $t->unsignedInteger('accepted');
            $t->unsignedInteger('damaged');
            $t->unsignedInteger('missingAfter');
            $t->unsignedInteger('damageAfter');
            $t->string('disposition',20)->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('stock_receipts')->exists()) {
            throw new \RuntimeException('Stock receipt history exists; do not remove its schema.');
        }
        Schema::dropIfExists('receiving_obligation_events');
        Schema::table('inventory_batches', function (Blueprint $t) { $t->dropIndex(['physicalLotId']); $t->dropColumn('physicalLotId'); });
        Schema::table('receiving_record_lines', function (Blueprint $t) { $t->dropIndex(['physicalLotId']); $t->dropColumn(['physicalLotId','originalProductId']); });
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn('damageOutstanding'));
        Schema::dropIfExists('physical_inventory_lots');
        Schema::dropIfExists('stock_receipts');
    }
};
