<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['workspace_id', 'name']);
        });
        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropUnique(['category', 'name']);
            $table->unique(['workspace_id', 'category', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->unique('name');
            $table->dropUnique(['workspace_id', 'name']);
        });
        Schema::table('subcategories', function (Blueprint $table) {
            $table->unique(['category', 'name']);
            $table->dropUnique(['workspace_id', 'category', 'name']);
        });
    }
};
