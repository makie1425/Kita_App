<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        DB::table('categories')->insert(['name' => 'Medical', 'status' => 'Active', 'classification' => 'Non-Perishable']);
        DB::table('suppliers')->insert(['id' => 1, 'name' => 'Supplier', 'status' => 'Active']);
        $this->actingAs($this->manager);
    }

    private function item(array $replace = []): array
    {
        return array_replace(['name' => 'Mask', 'category' => 'Medical', 'vatClass' => 'VATable', 'price' => '12.50', 'unitPrice' => '3.25', 'stock' => 0, 'barcode' => 'MASK001', 'supplierId' => 1, 'purchaseUnit' => 'Piece', 'stockUnit' => 'Piece', 'conversionFactor' => 1, 'status' => 'Active'], $replace);
    }

    private function createItem(): int
    {
        return $this->postJson('/api/products', $this->item())->assertCreated()->json('product.id');
    }

    public function test_item_registration_starts_empty_and_protects_unique_stock_id(): void
    {
        $id = $this->createItem();
        $this->assertDatabaseHas('products', ['id' => $id, 'cost' => 0, 'unitPrice' => 3.25, 'registrationQuantity' => 0, 'stock' => 0]);
        $this->postJson('/api/products', $this->item(['id' => $id, 'barcode' => 'NEW']))->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson('/api/products', $this->item())->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $id2 = $this->postJson('/api/products', $this->item(['barcode' => 'MASK002']))->assertCreated()->json('product.id');
        $this->assertNotEquals($id, $id2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_invalid_items_fail_without_saving(): void
    {
        foreach ([['name' => ''], ['category' => 'Missing'], ['stock' => -1], ['stock' => 1.2], ['unitPrice' => 'bad'], ['price' => ''], ['price' => -1], ['unitPrice' => '1.234'], ['stockUnit' => ''], ['supplierId' => 999], ['id' => 'abc']] as $invalid) {
            $this->postJson('/api/products', $this->item($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_manager_can_request_a_new_product_in_a_new_category_without_stock(): void
    {
        $this->postJson('/api/categories', ['name' => 'New Supplies', 'classification' => 'Non-Perishable'])->assertCreated();
        $id = $this->postJson('/api/products', $this->item(['category' => 'New Supplies', 'stock' => 0]))
            ->assertCreated()->json('product.id');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 0, 'cost' => 0, 'registrationQuantity' => 0]);
        $requestId = $this->postJson('/api/purchase-requests', [
            'category' => 'New Supplies',
            'lines' => [['productId' => $id, 'qty' => 5, 'supplierId' => 1]],
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('item_request_lines', ['requestId' => $requestId, 'productId' => $id, 'qty' => 5]);
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 0]);
    }

    public function test_price_update_preserves_original_registration_quantity_after_stock_changes(): void
    {
        $id = $this->createItem();
        DB::table('products')->where('id', $id)->update(['stock' => 2]);
        $this->patchJson('/api/products/'.$id, $this->item(['stock' => 2, 'unitPrice' => 4, 'reason' => 'New supplier price']))->assertOk();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 2, 'cost' => 0, 'registrationQuantity' => 0]);
        $this->patchJson('/api/products/'.$id, $this->item(['stock' => 3]))->assertUnprocessable();
        $this->assertDatabaseCount('field_version_history', 1);
    }

    public function test_category_classification_and_duplicate_validation(): void
    {
        $this->postJson('/api/categories', ['name' => 'Food', 'classification' => 'Perishable'])->assertCreated();
        $this->postJson('/api/categories', ['name' => ' food ', 'classification' => 'Perishable'])->assertUnprocessable();
        $this->postJson('/api/categories', ['name' => 'Other', 'classification' => 'Invalid'])->assertUnprocessable();
        $this->patchJson('/api/categories/Food', ['classification' => 'Non-Perishable'])->assertOk();
        $this->assertDatabaseHas('categories', ['name' => 'Food', 'classification' => 'Non-Perishable']);
    }

    public function test_large_current_stock_does_not_change_registration_cost_validation(): void
    {
        $id = $this->createItem();
        DB::table('products')->where('id', $id)->update(['stock' => 100000000]);
        $this->patchJson('/api/products/'.$id, $this->item(['stock' => 100000000, 'unitPrice' => 4, 'reason' => 'Updated unit cost']))
            ->assertOk()->assertJsonPath('product.cost', fn ($value) => (float) $value === 0.0);
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 100000000, 'registrationQuantity' => 0, 'cost' => 0]);
    }

    public function test_adjustments_require_admin_and_preserve_history(): void
    {
        $id = $this->createItem();
        DB::table('products')->where('id', $id)->update(['stock' => 4]);
        foreach ([-1, -2] as $delta) {
            $adjustment = $this->actingAs($this->manager)->postJson('/api/inventory/adjustments', ['productId' => $id, 'qtyChange' => $delta, 'reason' => 'Count correction', 'comment' => 'Count checked'])->assertCreated()->json('id');
            $this->patchJson('/api/inventory/adjustments/'.$adjustment, ['status' => 'Approved'])->assertForbidden();
            $this->actingAs($this->admin)->patchJson('/api/inventory/adjustments/'.$adjustment, ['status' => 'Approved'])->assertOk();
            $this->patchJson('/api/inventory/adjustments/'.$adjustment, ['status' => 'Approved'])->assertConflict();
            $this->patchJson('/api/inventory/adjustments/'.$adjustment, ['status' => 'Rejected'])->assertConflict();
        }
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 1]);
        $this->assertDatabaseCount('adjustments', 2);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_adjustment_rechecks_stock_at_approval(): void
    {
        $id = $this->createItem();
        DB::table('products')->where('id', $id)->update(['stock' => 4]);
        $body = ['productId' => $id, 'qtyChange' => -4, 'reason' => 'Damage', 'comment' => 'Broken'];
        $adjustment = $this->postJson('/api/inventory/adjustments', $body)->assertCreated()->json('id');
        DB::table('products')->where('id', $id)->update(['stock' => 1]);
        $this->actingAs($this->admin)->patchJson('/api/inventory/adjustments/'.$adjustment, ['status' => 'Approved'])->assertUnprocessable();
        $this->assertDatabaseHas('adjustments', ['id' => $adjustment, 'status' => 'Pending Admin Approval']);
        foreach ([0, -9, 1.5, 'bad'] as $delta) {
            $this->postJson('/api/inventory/adjustments', array_replace($body, ['qtyChange' => $delta]))->assertUnprocessable();
        }
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 1]);
    }

    public function test_purchase_order_combines_items_and_computes_total(): void
    {
        $id = $this->createItem();
        $request = $this->postJson('/api/purchase-requests', ['category' => 'Medical', 'lines' => [['productId' => $id, 'qty' => 2], ['productId' => $id, 'qty' => 3]]])->assertCreated()->json('id');
        $this->assertDatabaseCount('item_request_lines', 1);
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$request, ['action' => 'modify', 'note' => 'Adjusted for current demand.', 'lines' => [['productId' => $id, 'qty' => 4]]])->assertOk();
        $this->assertDatabaseHas('item_requests', ['id' => $request, 'status' => 'Pending Approval', 'adminNote' => 'Adjusted for current demand.']);
        $this->assertDatabaseHas('item_request_lines', ['requestId' => $request, 'productId' => $id, 'confirmedQty' => 4]);
        $po = $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$request, ['action' => 'approved'])->assertOk()->json('poId');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po, 'orderedValue' => 13.00]);
        $this->patchJson('/api/purchase-requests/'.$request, ['action' => 'approved'])->assertConflict();
        $this->assertSame($request, $po);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$po.'/items', ['lines' => [['productId' => $id, 'qty' => 2]]])->assertConflict();
        // Legacy draft editing remains available; newly approved POs are immutable.
        DB::table('purchase_orders')->insert(['id' => 'LEGACY-DRAFT', 'supplierId' => 1, 'status' => 'Draft', 'orderedValue' => 13, 'outstandingValue' => 13]);
        DB::table('purchase_order_lines')->insert(['poId' => 'LEGACY-DRAFT', 'productId' => $id, 'name' => 'Mask', 'orderedQty' => 4, 'unitCost' => 3.25, 'lineTotal' => 13, 'deliveredQty' => 0]);
        $po = 'LEGACY-DRAFT';
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$po.'/items', ['lines' => [['productId' => $id, 'qty' => 2, 'unitCost' => 3.25]], 'orderedValue' => 1])->assertOk()->assertJsonPath('orderedValue', 19.5);
        $this->assertDatabaseCount('purchase_order_lines', 2);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $po, 'orderedQty' => 6, 'lineTotal' => 19.5]);
        foreach ([['qty' => 0], ['qty' => -1], ['qty' => 1.2], ['unitCost' => 'bad'], ['unitCost' => -1], ['unitCost' => ''], ['productId' => 999]] as $invalid) {
            $this->postJson('/api/purchase-orders/'.$po.'/items', ['lines' => [array_replace(['productId' => $id, 'qty' => 1, 'unitCost' => 3.25], $invalid)]])->assertUnprocessable();
        }
        DB::table('purchase_orders')->where('id', $po)->update(['status' => 'Closed']);
        $this->postJson('/api/purchase-orders/'.$po.'/items', ['lines' => [['productId' => $id, 'qty' => 1]]])->assertConflict();
    }

    public function test_account_creation_uniqueness_permissions_and_no_delete(): void
    {
        $body = ['name' => 'New cashier', 'email' => 'NEW@EXAMPLE.COM', 'role' => 'cashier', 'password' => 'secret123'];
        $account = $this->postJson('/api/accounts', $body)->assertCreated()->json('account');
        $this->assertSame('new@example.com', $account['email']);
        $this->assertTrue(Hash::check('secret123', User::where('email', $account['email'])->first()->password));
        $this->postJson('/api/accounts', $body)->assertUnprocessable();
        $this->postJson('/api/accounts', array_replace($body, ['email' => 'admin@example.com', 'role' => 'admin']))->assertForbidden();
        $this->deleteJson('/api/accounts/cashier/'.$account['id'])->assertStatus(405);
        $this->patchJson('/api/accounts/cashier/'.$account['id'], ['status' => 'Inactive'])->assertOk();
        $this->assertDatabaseHas('users', ['email' => $account['email'], 'status' => 'Inactive']);
    }

    public function test_ongoing_transaction_blocks_account_deactivation(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        DB::table('transactions')->insert(['uuid' => 'TXN-pending', 'status' => 'Pending Payment', 'cashierId' => $cashier->id, 'cashierRole' => $cashier->role, 'cashier' => $cashier->name, 'cashierEmail' => $cashier->email]);
        $this->patchJson('/api/accounts/cashier/'.$cashier->id, ['status' => 'Inactive'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('users', ['email' => $cashier->email, 'status' => 'Active']);
        DB::table('transactions')->where('uuid', 'TXN-pending')->update(['status' => 'Unused']);
        $this->patchJson('/api/accounts/cashier/'.$cashier->id, ['status' => 'Inactive'])->assertOk();
    }

    public function test_pending_request_blocks_manager_deactivation(): void
    {
        $id = $this->createItem();
        $this->postJson('/api/purchase-requests', ['category' => 'Medical', 'lines' => [['productId' => $id, 'qty' => 1]]])->assertCreated();
        $this->actingAs($this->admin)->patchJson('/api/accounts/manager/'.$this->manager->id, ['status' => 'Inactive'])->assertUnprocessable();
    }

    public function test_cashier_cannot_call_manager_or_admin_apis(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        foreach (['/api/accounts', '/api/categories', '/api/products', '/api/purchase-requests', '/api/inventory/adjustments'] as $url) {
            $this->postJson($url, [])->assertForbidden();
        }
    }

    public function test_super_admin_can_create_admins_but_admin_cannot_escalate(): void
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        $body = ['name' => 'New admin', 'email' => 'newadmin@example.com', 'role' => 'admin', 'password' => 'secret123'];
        $this->actingAs($super)->postJson('/api/accounts', $body)->assertCreated();
        $this->actingAs($this->admin)->patchJson('/api/accounts/superadmin/'.$super->id, ['status' => 'Inactive'])->assertForbidden();
        $this->actingAs($super)->patchJson('/api/accounts/superadmin/'.$super->id, ['status' => 'Inactive'])->assertUnprocessable();
    }

    public function test_catalog_returns_classification_unit_price_and_saved_receipt_lines(): void
    {
        $this->createItem();
        $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('PRODUCTS.0.unitPrice', 3.25)
            ->assertJsonPath('CATEGORIES.0.classification', 'Non-Perishable');
    }
}
