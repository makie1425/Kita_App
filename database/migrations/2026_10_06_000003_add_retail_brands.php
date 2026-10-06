<?php

use Database\Seeders\RetailBrandSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing stores receive the catalog on upgrade; new stores use DatabaseSeeder.
        if (DB::table('categories')->exists()) {
            (new RetailBrandSeeder)->run();
        }
    }

    public function down(): void
    {
        // Preserve brands that may already be assigned to products.
    }
};
