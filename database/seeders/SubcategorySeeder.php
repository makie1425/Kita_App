<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubcategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $category = DB::table('categories')->whereRaw('LOWER(name) = ?', ['beverages'])->value('name');

            if ($category === null) {
                $category = 'Beverages';
                DB::table('categories')->insert([
                    'name' => $category,
                    'classification' => 'Non-Perishable',
                    'status' => 'Active',
                ]);
            }

            foreach (['Soft Drinks', 'Juice', 'Water'] as $name) {
                $exists = DB::table('subcategories')
                    ->where('category', $category)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->exists();

                if (! $exists) {
                    DB::table('subcategories')->insert([
                        'category' => $category,
                        'name' => $name,
                        'status' => 'Active',
                    ]);
                }
            }
        });
    }
}
