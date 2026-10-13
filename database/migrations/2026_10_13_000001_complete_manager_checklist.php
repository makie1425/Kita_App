<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $t) {
            $t->string('name')->primary();
            $t->unsignedInteger('value');
        });
        DB::table('inventory_settings')->insert(['name' => 'expiry_days', 'value' => 7]);
        DB::table('inventory_sequences')->insertOrIgnore(['name' => 'promotions', 'lastValue' => (int) DB::table('promotions')->max('id')]);
        Schema::create('adjustment_evidence', function (Blueprint $t) {
            $t->string('adjustmentId', 30)->primary();
            $t->string('mime', 40);
            $t->longText('content');
        });
        Schema::create('supplier_contacts', function (Blueprint $t) {
            $t->id();
            $t->uuid('requestKey')->unique();
            $t->string('receiptId', 30)->index();
            $t->integer('supplierId');
            $t->date('contactDate');
            $t->string('person', 160);
            $t->string('channel', 30);
            $t->text('notes');
            $t->text('outcome');
            $t->string('actor');
            $t->timestamp('created_at');
        });
        Schema::create('override_decisions', function (Blueprint $t) {
            $t->id();
            $t->uuid('requestKey')->unique();
            $t->integer('managerId');
            $t->integer('requestedById');
            $t->string('requestedByRole', 30);
            $t->string('decision', 30);
            $t->text('reason');
            $t->text('items');
            $t->timestamp('created_at');
        });
        foreach (['inventory_batches', 'receiving_record_lines'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->string('lotNumber', 40)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['inventory_batches', 'receiving_record_lines'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('lotNumber'));
        }
        foreach (['override_decisions', 'supplier_contacts', 'adjustment_evidence', 'inventory_settings'] as $name) {
            Schema::dropIfExists($name);
        }
        DB::table('inventory_sequences')->where('name', 'promotions')->delete();
    }
};
