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
            foreach (\App\Services\WorkspaceRead::table('categories')->pluck('name') as $category) {
                foreach ($catalog as $label => $names) {
                    if (mb_strtolower(trim($category)) !== mb_strtolower($label)) {
                        continue;
                    }
                    foreach ($names as $name) {
                        if (! \App\Services\WorkspaceRead::table('subcategories')->where('category', $category)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->exists()) {
                            \App\Services\WorkspaceRead::table('subcategories')->insert(\App\Services\WorkspaceWrite::owned([
                                'category' => $category, 'name' => $name, 'status' => 'Active',
                            ]));
                        }
                    }
                }
            }
        });
    }
}
