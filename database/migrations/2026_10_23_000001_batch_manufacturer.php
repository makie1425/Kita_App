<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('products', fn(Blueprint $t)=>$t->string('manufacturer',160)->nullable());
        Schema::table('inventory_batches', fn(Blueprint $t)=>$t->string('manufacturer',160)->nullable());
    }
    public function down(): void {
        Schema::table('inventory_batches', fn(Blueprint $t)=>$t->dropColumn('manufacturer'));
        Schema::table('products', fn(Blueprint $t)=>$t->dropColumn('manufacturer'));
    }
};
