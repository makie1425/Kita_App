<?php

use Database\Seeders\RetailSubcategorySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new RetailSubcategorySeeder)->run();
    }

    public function down(): void
    {
        // Preserve catalog records that may already be linked to products.
    }
};
