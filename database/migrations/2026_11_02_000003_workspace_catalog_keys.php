<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_barcode_unique');
            $table->unique(['workspace_id', 'barcode'], 'products_workspace_barcode_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::create('categories_workspace_new', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('status', 30)->nullable();
                $table->date('archivedAt')->nullable();
                $table->string('archivedBy', 120)->nullable();
                $table->string('classification', 30)->nullable();
                $table->string('normalized_name', 100)->virtualAs('LOWER(TRIM(name))');
                $table->timestamp('created_at', 6)->nullable();
                $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
                $table->unique(['workspace_id', 'normalized_name'], 'categories_workspace_normalized_unique');
            });
            foreach (DB::table('categories')->get() as $category) {
                $values = (array) $category;
                unset($values['normalized_name']);
                DB::table('categories_workspace_new')->insert($values);
            }
            Schema::drop('categories');
            Schema::rename('categories_workspace_new', 'categories');
            Schema::table('categories', fn (Blueprint $table) => $table->index('created_at', 'categories_created_at_index'));
        } else {
            DB::statement('ALTER TABLE categories DROP PRIMARY KEY, DROP INDEX categories_normalized_name_unique, ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, ADD UNIQUE INDEX categories_workspace_normalized_unique (workspace_id, normalized_name)');
        }
    }

    public function down(): void
    {
        if (DB::table('categories')->select('normalized_name')->groupBy('normalized_name')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('products')->whereNotNull('barcode')->select('barcode')->groupBy('barcode')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Review duplicate category names and barcodes before rollback. No records changed.');
        }
        if (DB::getDriverName() === 'sqlite') {
            Schema::create('categories_legacy_restore', function (Blueprint $table) {
                $table->string('name', 100)->primary();
                $table->string('status', 30)->nullable();
                $table->date('archivedAt')->nullable();
                $table->string('archivedBy', 120)->nullable();
                $table->string('classification', 30)->nullable();
                $table->string('normalized_name', 100)->virtualAs('LOWER(TRIM(name))');
                $table->timestamp('created_at', 6)->nullable();
                $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
                $table->unique('normalized_name', 'categories_normalized_name_unique');
            });
            foreach (DB::table('categories')->get() as $category) {
                $values = (array) $category;
                unset($values['id'], $values['normalized_name']);
                DB::table('categories_legacy_restore')->insert($values);
            }
            Schema::drop('categories');
            Schema::rename('categories_legacy_restore', 'categories');
            Schema::table('categories', fn (Blueprint $table) => $table->index('created_at', 'categories_created_at_index'));
        } else {
            DB::statement('ALTER TABLE categories DROP COLUMN id, DROP INDEX categories_workspace_normalized_unique, ADD PRIMARY KEY (name), ADD UNIQUE INDEX categories_normalized_name_unique (normalized_name)');
        }
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_workspace_barcode_unique');
            $table->unique('barcode', 'products_barcode_unique');
        });
    }
};
