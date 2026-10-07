<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', fn (Blueprint $t) => $t->boolean('recalled')->default(false));
        Schema::create('inventory_events', function (Blueprint $t) {
            $t->id();
            $t->string('requestKey', 36)->unique();
            $t->unsignedBigInteger('batchId');
            $t->integer('productId');
            $t->string('type', 30);
            $t->integer('quantity');
            $t->string('destination')->nullable();
            $t->text('reason');
            $t->string('actor');
            $t->timestamp('created_at');
        });
        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->id();
            $t->string('mode', 20);
            $t->string('category', 100)->nullable();
            $t->string('status', 20)->default('Open');
            $t->string('actor');
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('closed_at')->nullable();
        });
        Schema::create('inventory_count_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('countId');
            $t->integer('productId');
            $t->integer('expected');
            $t->unsignedBigInteger('movementId')->default(0);
            $t->integer('counted')->nullable();
            $t->unique(['countId', 'productId']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', fn (Blueprint $t) => $t->dropColumn('recalled'));
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('inventory_events');
    }
};
