<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'description')) {
            Schema::table('products', fn (Blueprint $table) => $table->text('description')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'description')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('description'));
        }
    }
};
