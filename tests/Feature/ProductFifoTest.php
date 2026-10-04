<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FifoInventory;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductFifoTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private int $product;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->manager);
        $this->postJson('/api/categories', ['name' => 'Beverages', 'classification' => 'Non-Perishable'])->assertCreated();
        $brand = $this->postJson('/api/product-master/brands', ['name' => 'Coca-Cola', 'status' => 'Active'])->assertOk()->json('record.id');
        $sub = $this->postJson('/api/product-master/subcategories', ['name' => 'Soft Drinks', 'category' => 'Beverages', 'status' => 'Active'])->assertOk()->json('record.id');
        $supplier = $this->postJson('/api/suppliers', ['name' => 'Beverage Supplier'])->assertCreated()->json('supplier.id');
        $this->input = ['name' => 'Coke', 'brandId' => $brand, 'subcategoryId' => $sub, 'category' => 'Beverages', 'size' => 1, 'sizeUnit' => 'Liter',
            'stockUnit' => 'Bottle', 'purchaseUnit' => 'Bottle', 'conversionFactor' => 1, 'barcode' => 'COKE-1L', 'price' => 80, 'unitPrice' => 55, 'supplierId' => $supplier, 'minStock' => 10];
        $this->product = $this->postJson('/api/products', $this->input)->assertCreated()->assertJsonPath('product.stock', 0)->json('product.id');
    }

    private function receive(int $qty, float $cost): string
    {
        $this->actingAs($this->manager);
        $id = $this->postJson('/api/purchase-requests', ['category' => 'Beverages', 'lines' => [['productId' => $this->product, 'qty' => $qty]]])->assertCreated()->json('id');
        $before = DB::table('products')->where('id', $this->product)->value('stock');
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 0])->assertOk();
        $this->assertEquals($before, DB::table('products')->where('id', $this->product)->value('stock'));
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', ['version' => 0, 'idempotencyKey' => (string) Str::uuid(),
            'receivedDate' => now()->toDateString(), 'lines' => [['productId' => $this->product, 'qty' => $qty, 'unitCost' => $cost, 'batchNumber' => 'DELIVERY-'.$qty]]])->assertCreated();

        return $id;
    }

    public function test_chairperson_full_workflow_two_costs_and_fifo_sale(): void
    {
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0, 'brandId' => $this->input['brandId'], 'size' => 1]);
        $this->receive(20, 55);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 20]);
        $this->receive(10, 60);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 30]);
        $this->postJson('/api/transactions', ['uuid' => 'FIFO-SALE', 'items' => [['productId' => $this->product, 'qty' => 25]], 'paymentMode' => 'Cash', 'tendered' => 2000])->assertCreated();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 5]);
        $this->assertDatabaseHas('inventory_batches', ['productId' => $this->product, 'unitCost' => 55, 'quantityRemaining' => 0]);
        $this->assertDatabaseHas('inventory_batches', ['productId' => $this->product, 'unitCost' => 60, 'quantityRemaining' => 5]);
        $this->assertEquals(1400, DB::table('inventory_allocations')->selectRaw('SUM(quantity * unitCost) as total')->value('total'));
        $this->assertDatabaseHas('receiving_record_lines', ['productId' => $this->product, 'unitCost' => 55]);
        $this->assertDatabaseHas('receiving_record_lines', ['productId' => $this->product, 'unitCost' => 60]);
        $this->getJson('/api/inventory-history?brandId='.$this->input['brandId'])->assertOk()->assertJsonCount(2, 'batches');
    }

    public function test_registration_and_manual_adjustments_cannot_add_stock(): void
    {
        $this->postJson('/api/products', array_replace($this->input, ['barcode' => 'OTHER', 'stock' => 10]))->assertUnprocessable();
        $this->patchJson('/api/products/'.$this->product, array_replace($this->input, ['stock' => 10]))->assertUnprocessable();
        $this->postJson('/api/inventory/adjustments', ['productId' => $this->product, 'qtyChange' => 10, 'reason' => 'Manual', 'comment' => 'Bypass'])->assertUnprocessable();
        $this->postJson('/api/purchase-requests', ['category' => 'Beverages', 'lines' => [['productId' => 999999, 'qty' => 2]]])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
    }

    public function test_subcategory_must_belong_to_category_and_master_records_are_restricted(): void
    {
        $this->postJson('/api/categories', ['name' => 'Other', 'classification' => 'Non-Perishable'])->assertCreated();
        $this->postJson('/api/products', array_replace($this->input, ['barcode' => 'WRONG', 'category' => 'Other']))->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'cashier']))->postJson('/api/product-master/brands', ['name' => 'Denied', 'status' => 'Active'])->assertForbidden();
        $this->getJson('/api/inventory-history')->assertForbidden();
    }

    public function test_reservation_release_restores_original_costs_and_order(): void
    {
        $this->receive(20, 55);
        $this->receive(10, 60);
        DB::transaction(function () {
            DB::table('products')->where('id', $this->product)->update(['stock' => 5]);
            StockMovement::record($this->product, 30, 5, 'payment_reservation', 'RESERVE');
            DB::table('products')->where('id', $this->product)->update(['stock' => 30]);
            StockMovement::record($this->product, 5, 30, 'payment_release', 'RESERVE');
        });
        $this->assertDatabaseHas('inventory_batches', ['unitCost' => 55, 'quantityRemaining' => 20]);
        $this->assertDatabaseHas('inventory_batches', ['unitCost' => 60, 'quantityRemaining' => 10]);
    }

    public function test_cost_changes_never_reprice_existing_batches_and_low_stock_remains_configured(): void
    {
        $this->receive(20, 55);
        $this->patchJson('/api/products/'.$this->product, array_replace($this->input, ['unitPrice' => 70, 'reason' => 'New estimate']))->assertOk();
        $this->assertDatabaseHas('inventory_batches', ['unitCost' => 55, 'quantityRemaining' => 20]);
        $this->assertDatabaseHas('products', ['minStock' => 10, 'stock' => 20]);
    }

    public function test_unrestocked_refund_does_not_restore_wrong_cost_on_next_return(): void
    {
        $this->receive(2, 55);
        $this->receive(2, 60);
        DB::transaction(function () {
            FifoInventory::consume($this->product, 4, 'checkout', 'RETURN');
            FifoInventory::returnStock($this->product, 2, 'RETURN', false);
            FifoInventory::returnStock($this->product, 1, 'RETURN', true);
        });
        $this->assertDatabaseHas('inventory_batches', ['unitCost' => 55, 'quantityRemaining' => 0]);
        $this->assertDatabaseHas('inventory_batches', ['unitCost' => 60, 'quantityRemaining' => 1]);
    }

    public function test_category_rename_preserves_product_hierarchy(): void
    {
        $this->patchJson('/api/categories/Beverages', ['name' => 'Drinks'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'category' => 'Drinks']);
        $this->assertDatabaseHas('subcategories', ['id' => $this->input['subcategoryId'], 'category' => 'Drinks']);
        $this->postJson('/api/categories', ['name' => 'Supplies', 'classification' => 'Non-Perishable'])->assertCreated();
        $payload = array_replace($this->input, ['category' => 'Supplies']);
        unset($payload['subcategoryId']);
        $this->patchJson('/api/products/'.$this->product, $payload)->assertUnprocessable();
    }

    public function test_negative_cost_and_invalid_expiry_are_rejected_without_stock_changes(): void
    {
        $id = $this->postJson('/api/purchase-requests', ['category' => 'Beverages', 'lines' => [['productId' => $this->product, 'qty' => 2]]])->assertCreated()->json('id');
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 0])->assertOk();
        foreach ([['unitCost' => -1], ['unitCost' => 55, 'expiryDate' => '2000-01-01']] as $invalid) {
            $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', ['version' => 0, 'idempotencyKey' => (string) Str::uuid(),
                'receivedDate' => now()->toDateString(), 'lines' => [array_merge(['productId' => $this->product, 'qty' => 2], $invalid)]])->assertUnprocessable();
        }
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    public function test_report_filters_include_zero_stock_products_and_validate_dates(): void
    {
        $this->getJson('/api/inventory-history?lowStock=1&brandId='.$this->input['brandId'])->assertOk()->assertJsonCount(1, 'products')->assertJsonCount(0, 'batches');
        $this->getJson('/api/inventory-history?category=Other')->assertOk()->assertJsonCount(0, 'products')->assertJsonCount(0, 'movements');
        $this->getJson('/api/inventory-history?from=invalid')->assertUnprocessable();
        $this->getJson('/api/inventory-history?to=2026-10-02')->assertOk();
    }

    public function test_price_edit_preserves_optional_metadata_and_unit_changes_are_blocked_after_receiving(): void
    {
        $this->receive(2, 55);
        $body = $this->input;
        unset($body['brandId'],$body['subcategoryId'],$body['size'],$body['sizeUnit'],$body['minStock']);
        $body['price'] = 85;
        $body['reason'] = 'New selling price';
        $this->patchJson('/api/products/'.$this->product, $body)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'brandId' => $this->input['brandId'], 'minStock' => 10]);
        $this->patchJson('/api/products/'.$this->product, array_replace($body,['stockUnit' => 'Box']))->assertUnprocessable();
    }
}
