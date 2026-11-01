<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RetailBrandSeeder extends Seeder
{
    public function run(): void
    {
        $names = require __DIR__.'/data/brands.php';
        DB::transaction(function () use ($names): void {
            foreach (array_unique($names) as $name) {
                if (! \App\Services\WorkspaceRead::table('brands')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->exists()) {
                    \App\Services\WorkspaceRead::table('brands')->insert(\App\Services\WorkspaceWrite::owned(['name' => $name, 'status' => 'Active']));
                }
            }
        });
    }
}
