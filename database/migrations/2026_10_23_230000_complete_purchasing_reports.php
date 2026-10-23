<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_requests', fn (Blueprint $t) => $t->date('expectedDeliveryDate')->nullable());
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->date('expectedDeliveryDate')->nullable();
            $t->date('supplierSentDate')->nullable();
            $t->string('supplierSentVia', 40)->nullable();
            $t->text('supplierSentNote')->nullable();
        });
        Schema::table('receiving_records', function (Blueprint $t) {
            $t->date('paymentDueDate')->nullable();
            $t->string('supplierDocument', 100)->nullable();
        });
        Schema::create('supplier_settlements', function (Blueprint $t) {
            $t->id();
            $t->uuid('requestKey')->unique();
            $t->string('payloadHash', 64);
            $t->string('receiptId', 100)->index();
            $t->string('kind', 20);
            $t->date('date');
            $t->decimal('amount', 12, 2);
            $t->string('reference', 100);
            $t->text('notes')->nullable();
            $t->integer('actorId');
            $t->string('actor', 120);
            $t->timestamp('created_at');
            $t->timestamp('voidedAt')->nullable();
            $t->text('voidReason')->nullable();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('supplier_settlements');
        Schema::table('receiving_records', fn (Blueprint $t) => $t->dropColumn(['paymentDueDate', 'supplierDocument']));
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn(['expectedDeliveryDate', 'supplierSentDate', 'supplierSentVia', 'supplierSentNote']));
        Schema::table('item_requests', fn (Blueprint $t) => $t->dropColumn('expectedDeliveryDate'));
    }
};
