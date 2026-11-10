<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing brands retain their IDs and remain unassigned until edited.
        if (! Schema::hasColumn('brands', 'category')) {
            Schema::table('brands', fn (Blueprint $table) => $table->string('category', 100)->nullable()->index());
        }
        if (! Schema::hasColumn('brands', 'subcategoryId')) {
            Schema::table('brands', fn (Blueprint $table) => $table->unsignedBigInteger('subcategoryId')->nullable()->index());
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['subcategoryId']);
            $table->dropColumn(['category', 'subcategoryId']);
        });
    }
};
