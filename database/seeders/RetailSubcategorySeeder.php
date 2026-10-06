<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RetailSubcategorySeeder extends Seeder
{
    public function run(): void
    {
        $catalog = require __DIR__.'/data/subcategories.php';
        DB::transaction(function () use ($catalog): void {
            foreach (DB::table('categories')->pluck('name') as $category) {
                foreach ($catalog as $label => $names) {
                    if (mb_strtolower(trim($category)) !== mb_strtolower($label)) {
                        continue;
                    }
                    foreach ($names as $name) {
                        if (! DB::table('subcategories')->where('category', $category)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->exists()) {
                            DB::table('subcategories')->insert([
                                'category' => $category, 'name' => $name, 'status' => 'Active',
                            ]);
                        }
                    }
                }
            }
        });
    }
}
