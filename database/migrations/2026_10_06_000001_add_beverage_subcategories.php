<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Populate existing installations; fresh catalogs use SubcategorySeeder.
        $category = DB::table('categories')->whereRaw('LOWER(TRIM(name)) = ?', ['beverages'])->value('name');
        if ($category === null) {
            return;
        }

        foreach (['Soft Drinks', 'Juice', 'Water'] as $name) {
            $exists = DB::table('subcategories')->where('category', $category)
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->exists();
            if (! $exists) {
                DB::table('subcategories')->insert([
                    'category' => $category,
                    'name' => $name,
                    'status' => 'Active',
                ]);
            }
        }
    }

    public function down(): void
    {
        // Retain catalog data: products may now reference these subcategories.
    }
};
