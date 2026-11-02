<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InventoryAvailability;
use App\Services\PurchaseWorkflow;
use App\Services\PurchasingReports;
use App\Services\SupplierAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedServiceWorkspaceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $name): User
    {
        $organization = DB::table('organizations')->insertGetId(['name' => $name]);
        $workspace = DB::table('workspaces')->insertGetId(['organization_id' => $organization, 'name' => $name]);
        $manager = User::factory()->create(['role' => 'manager', 'workspace_id' => $workspace]);
        DB::table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $manager->id, 'user_role' => 'manager', 'active' => true]);

        return $manager;
    }

    public function test_purchasing_report_and_supplier_balance_only_include_own_workspace(): void
    {
        $owner = $this->manager('Report owner');
        $other = $this->manager('Report other');
        foreach ([$owner, $other] as $manager) {
            DB::table('purchase_orders')->insert(['id' => 'PO-'.$manager->id, 'status' => 'Approved', 'created' => now()->toDateString(), 'workspace_id' => $manager->workspace_id]);
            DB::table('purchase_order_lines')->insert(['poId' => 'PO-'.$manager->id, 'productId' => $manager->id, 'name' => 'Item '.$manager->id, 'orderedQty' => 3, 'deliveredQty' => 0, 'workspace_id' => $manager->workspace_id]);
            DB::table('receiving_records')->insert(['id' => 'RCV-'.$manager->id, 'poId' => 'PO-'.$manager->id, 'date' => now()->toDateString(), 'workspace_id' => $manager->workspace_id]);
            DB::table('receiving_record_lines')->insert(['receivingId' => 'RCV-'.$manager->id, 'productId' => $manager->id, 'deliveredQty' => 1, 'unitCost' => 10, 'workspace_id' => $manager->workspace_id]);
        }

        $this->actingAs($owner);
        foreach (['outstanding_orders', 'purchases', 'payables', 'supplier_book'] as $type) {
            $request = Request::create('/reports/data', 'GET', ['type' => $type]);
            $request->setUserResolver(fn () => $owner);
            $report = PurchasingReports::data($request);
            $this->assertCount(1, $report['rows'], $type);
            $this->assertStringNotContainsString('PO-'.$other->id, json_encode($report['rows']));
        }
        $this->assertCount(1, SupplierAccounts::receipts()->get());
    }

    public function test_inventory_helper_rejects_foreign_supplied_products_and_batches(): void
    {
        $owner = $this->manager('Inventory owner');
        $other = $this->manager('Inventory other');
        foreach ([$owner, $other] as $manager) {
            DB::table('categories')->insert(['name' => 'Category '.$manager->id, 'status' => 'Active', 'workspace_id' => $manager->workspace_id]);
            DB::table('products')->insert(['id' => $manager->id, 'name' => 'Product '.$manager->id, 'category' => 'Category '.$manager->id, 'status' => 'Active', 'stock' => 0, 'workspace_id' => $manager->workspace_id]);
            DB::table('inventory_batches')->insert(['productId' => $manager->id, 'quantityReceived' => 1, 'quantityRemaining' => 1, 'unitCost' => 5, 'receivedDate' => now()->toDateString(), 'source' => 'legacy_opening', 'created_at' => now(), 'workspace_id' => $manager->workspace_id]);
        }

        $this->actingAs($owner);
        $products = DB::table('products')->get()->map(fn ($product) => (array) $product)->all();
        $this->assertCount(1, InventoryAvailability::products($products));
        $this->assertCount(1, InventoryAvailability::batches(DB::table('inventory_batches')->get()));
    }

    public function test_cashier_inventory_counts_reservations_from_every_cashier_in_same_workspace(): void
    {
        $manager = $this->manager('Shared inventory');
        $otherManager = $this->manager('Foreign inventory');
        $cashiers = [];
        foreach (['First', 'Second'] as $name) {
            $cashier = User::factory()->create(['role' => 'cashier', 'workspace_id' => $manager->workspace_id, 'assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager']);
            DB::table('workspace_memberships')->insert(['workspace_id' => $manager->workspace_id, 'user_id' => $cashier->id, 'user_role' => 'cashier', 'active' => true]);
            $cashiers[] = $cashier;
        }
        DB::table('categories')->insert(['name' => 'Shared', 'status' => 'Active', 'workspace_id' => $manager->workspace_id]);
        DB::table('products')->insert(['id' => 100, 'name' => 'Shared item', 'category' => 'Shared', 'status' => 'Active', 'stock' => 2, 'workspace_id' => $manager->workspace_id]);
        $batchId = DB::table('inventory_batches')->insertGetId(['productId' => 100, 'quantityReceived' => 2, 'quantityRemaining' => 1, 'unitCost' => 5, 'receivedDate' => now()->toDateString(), 'source' => 'legacy_opening', 'created_at' => now(), 'workspace_id' => $manager->workspace_id]);
        DB::table('transactions')->insert([
            ['uuid' => 'OWN-PENDING', 'date' => now()->toDateString(), 'status' => 'Pending Payment', 'cashierId' => $cashiers[0]->id, 'cashierRole' => 'cashier', 'workspace_id' => $manager->workspace_id],
            ['uuid' => 'FOREIGN-PENDING', 'date' => now()->toDateString(), 'status' => 'Pending Payment', 'cashierId' => $otherManager->id, 'cashierRole' => 'manager', 'workspace_id' => $otherManager->workspace_id],
        ]);
        DB::table('inventory_allocations')->insert([
            ['batchId' => $batchId, 'productId' => 100, 'referenceType' => 'payment_reservation', 'referenceId' => 'OWN-PENDING', 'quantity' => 1, 'unitCost' => 5, 'created_at' => now(), 'workspace_id' => $manager->workspace_id],
            ['batchId' => $batchId, 'productId' => 100, 'referenceType' => 'payment_reservation', 'referenceId' => 'FOREIGN-PENDING', 'quantity' => 9, 'unitCost' => 5, 'created_at' => now(), 'workspace_id' => $manager->workspace_id],
        ]);

        $this->actingAs($cashiers[1]);
        $this->assertSame(1, (int) InventoryAvailability::reservations()->first()->reserved);
        $stock = InventoryAvailability::products([(array) DB::table('products')->where('id', 100)->first()])[0];
        $this->assertSame(1, $stock['reservedStock']);
        $this->assertSame(2, $stock['stockOnHand']);
    }

    public function test_purchase_review_cannot_open_foreign_request(): void
    {
        $owner = $this->manager('Purchase owner');
        $other = $this->manager('Purchase other');
        DB::table('item_requests')->insert(['id' => 'REQ-OTHER', 'status' => 'Pending Approval', 'workspace_id' => $other->workspace_id]);
        $admin = User::factory()->create(['role' => 'admin', 'workspace_id' => $owner->workspace_id]);
        DB::table('workspace_memberships')->insert(['workspace_id' => $owner->workspace_id, 'user_id' => $admin->id, 'user_role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->patchJson('/api/purchase-requests/REQ-OTHER', ['action' => 'disapproved', 'note' => 'No'])->assertNotFound();
        $this->assertDatabaseHas('item_requests', ['id' => 'REQ-OTHER', 'status' => 'Pending Approval']);
    }
}
