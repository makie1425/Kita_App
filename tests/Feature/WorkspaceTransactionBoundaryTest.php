<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceTransactionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function team(string $name): array
    {
        $org = DB::table('organizations')->insertGetId(['name' => $name]);
        $scope = DB::table('workspaces')->insertGetId(['organization_id' => $org, 'name' => $name]);
        $manager = User::factory()->create(['role' => 'manager', 'workspace_id' => $scope]);
        $cashier = User::factory()->create(['role' => 'cashier', 'workspace_id' => $scope, 'assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager']);
        foreach ([$manager, $cashier] as $user) {
            DB::table('workspace_memberships')->insert(['workspace_id' => $scope, 'user_id' => $user->id, 'user_role' => $user->role, 'active' => true]);
        }
        return [$manager, $cashier, $scope];
    }

    public function test_transaction_urls_and_mutations_reject_other_teams_and_other_cashiers(): void
    {
        [$manager, $cashier, $scope] = $this->team('A');
        [$otherManager, $otherCashier] = $this->team('B');
        DB::table('transactions')->insert(['uuid' => 'PRIVATE-SALE', 'workspace_id' => $scope, 'cashierId' => $cashier->id, 'cashierRole' => 'cashier', 'status' => 'Unused']);
        foreach ([$otherManager, $otherCashier] as $intruder) {
            $this->actingAs($intruder)->getJson('/api/transactions/PRIVATE-SALE')->assertNotFound();
            $this->patchJson('/api/transactions/PRIVATE-SALE', ['status' => 'Cancelled'])->assertNotFound();
        }
        $sibling = User::factory()->create(['role' => 'cashier', 'workspace_id' => $scope, 'assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager']);
        DB::table('workspace_memberships')->insert(['workspace_id' => $scope, 'user_id' => $sibling->id, 'user_role' => 'cashier', 'active' => true]);
        $this->actingAs($sibling)->getJson('/api/transactions/PRIVATE-SALE')->assertNotFound();
        $this->actingAs($cashier)->getJson('/api/transactions/PRIVATE-SALE')->assertOk();
        $this->actingAs($manager)->getJson('/api/transactions/PRIVATE-SALE')->assertOk();
        $this->assertDatabaseHas('transactions', ['uuid' => 'PRIVATE-SALE', 'status' => 'Unused']);
    }

    public function test_checkout_stamps_ownership_and_rejects_foreign_inventory(): void
    {
        [, $cashier, $scope] = $this->team('Checkout');
        [, , $foreign] = $this->team('Other');
        foreach ([$scope, $foreign] as $id) {
            DB::table('categories')->insert(['name' => 'Food', 'status' => 'Active', 'workspace_id' => $id]);
            DB::table('products')->insert(['id' => $id, 'name' => 'Food item', 'category' => 'Food', 'barcode' => 'SAME-BARCODE', 'status' => 'Active', 'stock' => 3, 'price' => 10, 'workspace_id' => $id]);
        }
        $this->actingAs($cashier)->postJson('/api/transactions', ['uuid' => 'OWN-CHECKOUT', 'items' => [['productId' => $scope, 'qty' => 1]], 'paymentMode' => 'Cash', 'tendered' => 10, 'workspace_id' => $foreign])->assertCreated();
        $this->assertDatabaseHas('transactions', ['uuid' => 'OWN-CHECKOUT', 'workspace_id' => $scope, 'cashierId' => $cashier->id]);
        $this->assertDatabaseHas('transaction_lines', ['transaction_uuid' => 'OWN-CHECKOUT', 'workspace_id' => $scope]);
        $this->postJson('/api/transactions', ['uuid' => 'FOREIGN-CHECKOUT', 'items' => [['productId' => $foreign, 'qty' => 1]], 'paymentMode' => 'Cash', 'tendered' => 10])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $foreign, 'stock' => 3]);
    }

    public function test_catalog_migration_preserves_legacy_records_without_assigning_an_owner(): void
    {
        $migration = require database_path('migrations/2026_11_02_000003_workspace_catalog_keys.php');
        $migration->down();
        DB::table('categories')->insert(['name' => 'Historical', 'status' => 'Active', 'classification' => 'Non-Perishable']);
        $migration->up();
        $this->assertDatabaseHas('categories', ['name' => 'Historical', 'workspace_id' => null, 'classification' => 'Non-Perishable']);
    }

    public function test_super_admin_bootstrap_hides_business_records_and_blocks_business_routes(): void
    {
        [, , $scope] = $this->team('Private');
        $super = User::factory()->create(['role' => 'superadmin', 'workspace_id' => $scope]);
        DB::table('workspace_memberships')->insert(['workspace_id' => $scope, 'user_id' => $super->id, 'user_role' => 'super_admin', 'active' => true, 'can_manage_accounts' => true]);
        DB::table('products')->insert(['id' => 99, 'name' => 'Private stock', 'category' => 'Private', 'workspace_id' => $scope]);
        $this->actingAs($super)->getJson('/api/kita-data')->assertOk()->assertJsonCount(0, 'PRODUCTS')->assertJsonCount(0, 'USERS.cashier')->assertJsonCount(0, 'USERS.manager');
        $this->getJson('/api/purchasing')->assertForbidden();
        $this->getJson('/api/inventory-history')->assertForbidden();
        $this->getJson('/reports/data?type=sales')->assertForbidden();
    }
}
