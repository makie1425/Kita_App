<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshots include product name, brand and measurement, retaining variant identity.
        foreach (['transaction_lines', 'item_request_lines', 'purchase_order_lines', 'receiving_record_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('name', 320)->nullable()->change());
        }
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('status', 20)->default('Active');
        });
        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            $table->string('category', 100);
            $table->string('name', 100);
            $table->string('status', 20)->default('Active');
            $table->unique(['category', 'name']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brandId')->nullable()->constrained('brands');
            $table->foreignId('subcategoryId')->nullable()->constrained('subcategories');
            $table->decimal('size', 12, 3)->nullable();
            $table->string('sizeUnit', 20)->nullable();
        });
        Schema::table('receiving_record_lines', function (Blueprint $table) {
            $table->decimal('unitCost', 10, 2)->nullable();
            $table->string('batchNumber', 80)->nullable();
            $table->date('expiryDate')->nullable();
        });
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->integer('productId')->index();
            $table->string('receiptId', 40)->nullable()->index();
            $table->integer('supplierId')->nullable();
            $table->unsignedInteger('quantityReceived');
            $table->unsignedInteger('quantityRemaining');
            $table->decimal('unitCost', 10, 2);
            $table->date('receivedDate');
            $table->string('source', 30);
            $table->string('batchNumber', 80)->nullable();
            $table->date('expiryDate')->nullable();
            $table->timestamp('created_at');
            $table->index(['productId', 'receivedDate', 'id']);
        });
        Schema::create('inventory_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batchId')->constrained('inventory_batches');
            $table->integer('productId')->index();
            $table->string('referenceType', 40);
            $table->string('referenceId', 80)->index();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('returnedQuantity')->default(0);
            $table->decimal('unitCost', 10, 2);
            $table->timestamp('created_at');
        });
        // Preserve known stock. Do not invent historical deliveries or brand/size details.
        DB::table('products')->where('stock', '>', 0)->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $p) {
                DB::table('inventory_batches')->insert(['productId' => $p->id, 'quantityReceived' => $p->stock,
                    'quantityRemaining' => $p->stock, 'unitCost' => max(0, (float) ($p->unitPrice ?? 0)),
                    'receivedDate' => '1970-01-01', 'source' => 'legacy_opening', 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_allocations');
        Schema::dropIfExists('inventory_batches');
        Schema::table('receiving_record_lines', fn (Blueprint $t) => $t->dropColumn(['unitCost', 'batchNumber', 'expiryDate']));
        Schema::table('products', function (Blueprint $t) {
            $t->dropForeign(['brandId']);
            $t->dropForeign(['subcategoryId']);
            $t->dropColumn(['brandId', 'subcategoryId', 'size', 'sizeUnit']);
        });
        Schema::dropIfExists('subcategories');
        Schema::dropIfExists('brands');
    }
};
