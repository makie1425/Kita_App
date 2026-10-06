<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RetailSubcategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailSubcategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_populate_from_the_screen_without_duplicates(): void
    {
        DB::table('categories')->insert(['name' => 'Beverages', 'status' => 'Active']);
        $this->actingAs(User::factory()->create(['role' => 'manager', 'status' => 'Active']));
        $catalog = require database_path('seeders/data/subcategories.php');
        $this->postJson('/api/product-master/subcategories/populate')->assertOk()->assertJsonPath('added', count($catalog['Beverages']));
        $this->postJson('/api/product-master/subcategories/populate')->assertOk()->assertJsonPath('added', 0);
        $this->assertDatabaseHas('subcategories', ['name' => 'Energy Drinks', 'category' => 'Beverages']);
    }

    public function test_cashier_cannot_populate_subcategories(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'status' => 'Active']));
        $this->postJson('/api/product-master/subcategories/populate')->assertForbidden();
    }

    public function test_catalog_covers_each_category_and_preserves_existing_records(): void
    {
        $catalog = require database_path('seeders/data/subcategories.php');
        foreach (array_keys($catalog) as $category) {
            DB::table('categories')->insert(['name' => $category, 'status' => 'Active']);
        }
        $id = DB::table('subcategories')->insertGetId(['category' => 'Beverages', 'name' => 'juice', 'status' => 'Inactive']);
        DB::table('subcategories')->insert(['category' => 'Appliances', 'name' => 'Custom Equipment', 'status' => 'Active']);
        $this->seed(RetailSubcategorySeeder::class);
        $this->seed(RetailSubcategorySeeder::class);
        foreach ($catalog as $category => $names) {
            $this->assertSame(count($names) + ($category === 'Appliances' ? 1 : 0), DB::table('subcategories')->where('category', $category)->count());
        }
        $this->assertDatabaseHas('subcategories', ['id' => $id, 'name' => 'juice', 'status' => 'Inactive']);
        $this->assertDatabaseHas('subcategories', ['category' => 'Appliances', 'name' => 'Custom Equipment']);
    }

    public function test_only_existing_categories_are_populated_using_their_actual_names(): void
    {
        DB::table('categories')->insert(['name' => ' beverages ', 'status' => 'Active']);
        $this->seed(RetailSubcategorySeeder::class);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('subcategories', ['category' => ' beverages ', 'name' => 'Juice']);
        $this->assertDatabaseMissing('subcategories', ['category' => 'Appliances']);
    }
}
