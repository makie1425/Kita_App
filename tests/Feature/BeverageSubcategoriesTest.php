<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeverageSubcategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_adds_missing_beverage_options_without_overwriting_existing_data(): void
    {
        DB::table('categories')->insert(['name' => 'Beverages', 'status' => 'Active']);
        $id = DB::table('subcategories')->insertGetId([
            'name' => 'juice', 'category' => 'Beverages', 'status' => 'Inactive',
        ]);
        $migration = require database_path('migrations/2026_10_06_000001_add_beverage_subcategories.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('subcategories', 3);
        foreach (['Soft Drinks', 'Water'] as $name) {
            $this->assertDatabaseHas('subcategories', ['name' => $name, 'category' => 'Beverages', 'status' => 'Active']);
        }
        $this->assertDatabaseHas('subcategories', ['id' => $id, 'name' => 'juice', 'status' => 'Inactive']);
        $migration->down();
        $this->assertDatabaseCount('subcategories', 3);
    }
}
