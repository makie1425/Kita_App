<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_batches', 'receiving_record_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->date('manufacturedDate')->nullable());
        }
        // The original opening-balance migration omitted the known product expiry.
        DB::table('products')->whereNotNull('expiry')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                DB::table('inventory_batches')->where('productId', $product->id)->where('source', 'legacy_opening')
                    ->whereNull('expiryDate')->update(['expiryDate' => $product->expiry]);
            }
        });
        Schema::table('notifications', fn (Blueprint $table) => $table->string('deduplicationKey', 191)->nullable()->unique());
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('exchangeOriginalUuid', 30)->nullable()->index();
            $table->decimal('exchangeCredit', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['exchangeOriginalUuid']);
            $table->dropColumn(['exchangeOriginalUuid', 'exchangeCredit']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['deduplicationKey']);
            $table->dropColumn('deduplicationKey');
        });
        foreach (['inventory_batches', 'receiving_record_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('manufacturedDate'));
        }
    }
};
