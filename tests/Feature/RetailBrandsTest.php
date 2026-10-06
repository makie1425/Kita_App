<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailBrandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_populates_brands_and_preserves_existing_entries(): void
    {
        $id = DB::table('brands')->insertGetId(['name' => ' myphone ', 'status' => 'Inactive']);
        DB::table('brands')->insert(['name' => 'Local Store Brand', 'status' => 'Active']);
        $this->actingAs(User::factory()->create(['role' => 'manager', 'status' => 'Active']));
        $names = require database_path('seeders/data/brands.php');
        $this->postJson('/api/product-master/brands/populate')->assertOk()->assertJsonPath('added', count(array_unique($names)) - 1);
        $this->postJson('/api/product-master/brands/populate')->assertOk()->assertJsonPath('added', 0);
        foreach (['Samsung', 'Coca-Cola', 'Nestle', 'Dove', 'Tide', 'Pedigree', 'Bosch'] as $name) {
            $this->assertDatabaseHas('brands', ['name' => $name, 'status' => 'Active']);
        }
        $this->assertDatabaseHas('brands', ['id' => $id, 'name' => ' myphone ', 'status' => 'Inactive']);
        $this->assertDatabaseHas('brands', ['name' => 'Local Store Brand']);
    }

    public function test_cashier_cannot_import_brands(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'status' => 'Active']));
        $this->postJson('/api/product-master/brands/populate')->assertForbidden();
    }

    public function test_upgrade_populates_existing_store_without_duplicate_brands(): void
    {
        DB::table('categories')->insert(['name' => 'Beverages', 'status' => 'Active']);
        $migration = require database_path('migrations/2026_10_06_000003_add_retail_brands.php');
        $migration->up();
        $count = DB::table('brands')->count();
        $migration->up();
        $migration->down();
        $this->assertDatabaseCount('brands', $count);
        $this->assertGreaterThan(300, $count);
    }
}
