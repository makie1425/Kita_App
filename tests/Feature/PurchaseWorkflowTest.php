<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PurchaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $admin;
    private int $product;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'manager', 'name' => 'Receiving Manager']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Approving Admin']);
        $this->actingAs($this->manager);
        $this->supplier = $this->postJson('/api/suppliers', ['name' => 'Purchase Supplier', 'email' => 'supplier@example.com'])->assertCreated()->json('supplier.id');
        $this->postJson('/api/categories', ['name' => 'Supplies', 'classification' => 'Non-Perishable'])->assertCreated();
        $this->product = $this->postJson('/api/products', [
            'name' => 'New Item', 'category' => 'Supplies', 'barcode' => 'PURCHASE-ITEM', 'stock' => 0, 'unitPrice' => 2.50,
            'price' => 5, 'purchaseUnit' => 'Box', 'stockUnit' => 'Piece', 'conversionFactor' => 10, 'supplierId' => $this->supplier,
        ])->assertCreated()->json('product.id');
    }

    private function requestOrder(int $qty = 10): string
    {
        return $this->actingAs($this->manager)->postJson('/api/purchase-requests', ['category' => 'Supplies', 'notes' => 'For replenishment',
            'lines' => [['productId' => $this->product, 'qty' => $qty, 'supplierId' => $this->supplier]],
        ])->assertCreated()->assertJsonPath('status', 'Pending Approval')->json('id');
    }

    private function approve(string $id): void
    {
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 0])
            ->assertOk()->assertJsonPath('poId', $id)->assertJsonPath('status', PurchaseWorkflow::WAITING);
    }

    private function delivery(int $qty = 4, int $version = 0): array
    {
        return ['idempotencyKey' => (string) Str::uuid(), 'version' => $version,
            'receivedDate' => now()->toDateString(), 'lines' => [['productId' => $this->product, 'qty' => $qty]]];
    }

    public function test_complete_workflow_preserves_id_and_posts_only_actual_stock_with_printable_history(): void
    {
        $id = $this->requestOrder();
        $this->assertMatchesRegularExpression('/^PO-'.now()->format('Y').'-[0-9]{6,}$/', $id);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->actingAs($this->admin)->getJson('/api/purchasing')->assertOk()->assertJsonPath('requests.0.id', $id);
        $this->approve($id);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->assertDatabaseHas('notifications', ['recipientId' => $this->manager->id, 'recipientRole' => 'manager', 'recordId' => $id, 'type' => 'Purchase request Approved']);
        $this->assertDatabaseHas('item_requests', ['id' => $id, 'poId' => $id, 'approvedById' => $this->admin->id, 'approvedByRole' => 'admin']);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())
            ->assertCreated()->assertJsonPath('poId', $id)->assertJsonPath('status', 'Partially Received');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 4]);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(6, 1))->assertCreated()->assertJsonPath('status', 'Fully Received');
        $this->assertDatabaseHas('receiving_records', ['poId' => $id, 'deliveryReference' => $id.'-R001']);
        $this->assertDatabaseHas('receiving_records', ['poId' => $id, 'deliveryReference' => $id.'-R002']);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 10]);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 10, 'deliveredQty' => 10, 'unit' => 'Piece']);
        $this->assertSame(2, DB::table('stock_movements')->where('referenceType', 'purchase_receiving')->count());
        $this->assertSame(2, DB::table('notifications')->where('recipientId', $this->admin->id)->where('recipientRole', 'admin')->where('recordId', $id)->where('type', 'Stock received')->count());
        $this->actingAs($this->admin)->getJson('/api/purchasing')->assertOk()->assertJsonPath('orders.0.status', 'Fully Received')->assertJsonCount(2, 'orders.0.receipts');
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('PURCHASE ORDER / RECEIVING REPORT')->assertSee($id)
            ->assertSee('Purchase Supplier')->assertSee('Approving Admin')->assertSee('Receiving Manager')->assertSee('Print Report');
    }

    public function test_receiving_retry_and_stale_version_cannot_double_stock_and_reference_is_generated(): void
    {
        $id = $this->requestOrder();$this->approve($id);
        $body = $this->delivery();
        $body['deliveryReference'] = 'MANUAL-REFERENCE';
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertOk();
        $this->assertSame(2, DB::table('notifications')->where('recordId', $id)->where('type', 'Stock received')->count());
        $changed = $body;$changed['lines'][0]['qty'] = 5;
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $changed)->assertConflict();
        $stale = $this->delivery();$stale['deliveryReference'] = 'NEXT-DELIVERY';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $stale)->assertConflict();
        $duplicate = $this->delivery(4, 0);$duplicate['deliveryReference'] = ' delivery-0 ';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $duplicate)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 4]);
        $this->assertDatabaseCount('receiving_records', 1);
        $this->assertDatabaseHas('receiving_records', ['poId' => $id, 'deliveryReference' => $id.'-R001']);
    }

    public function test_declined_pending_and_unapproved_orders_cannot_receive(): void
    {
        $id = $this->requestOrder();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertNotFound();
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'disapproved'])->assertUnprocessable();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'disapproved', 'note' => 'Not needed'])->assertOk()->assertJsonPath('status', 'Declined');
        $this->actingAs($this->manager)->getJson('/api/purchasing')->assertOk()->assertJsonPath('requests.0.disapprovalReason', 'Not needed');
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertNotFound();
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
    }

    public function test_review_edits_same_request_rejects_stale_review_and_cannot_change_approved_quantities(): void
    {
        $id = $this->requestOrder();
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'modify', 'revision' => 0, 'lines' => [['productId' => $this->product, 'qty' => 3]]])->assertOk();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 0])->assertConflict();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 1])->assertOk()->assertJsonPath('poId', $id);
        $this->assertDatabaseHas('purchase_order_lines', ['poId' => $id, 'orderedQty' => 3]);
        $this->assertDatabaseCount('item_requests', 1);$this->assertDatabaseCount('purchase_orders', 1);
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved'])->assertConflict();
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/items', ['lines' => [['productId' => $this->product, 'qty' => 2]]])->assertConflict();
    }

    public function test_overdelivery_posts_actual_quantity_and_requires_notes_without_claiming_completion(): void
    {
        $id = $this->requestOrder(2);$this->approve($id);
        $body = $this->delivery(3);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $body['notes'] = 'Supplier delivered one extra piece, accepted by manager.';
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertCreated()->assertJsonPath('status', 'Received with Discrepancy');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 3]);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('Quantity discrepancies remain.');
    }

    public function test_invalid_delivery_rolls_back_stock_and_receipt_and_rejects_invalid_quantities(): void
    {
        $id = $this->requestOrder();$this->approve($id);
        $body = $this->delivery();$body['lines'][] = ['productId' => 999999, 'qty' => 1];
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        foreach ([0, -1, 1.5] as $qty) {
            $body = $this->delivery();$body['lines'][0]['qty'] = $qty;
            $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        }
        $body = $this->delivery();$body['receivedDate'] = now()->addDay()->toDateString();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $body)->assertUnprocessable();
        $this->assertDatabaseCount('receiving_records', 0);$this->assertDatabaseCount('receiving_record_lines', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 0]);
        $this->assertSame(0, DB::table('stock_movements')->where('referenceType', 'purchase_receiving')->count());
    }

    public function test_purchase_permissions_and_supplier_validation(): void
    {
        $id = $this->requestOrder();
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved'])->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => ' purchase supplier '])->assertUnprocessable();
        $this->approve($id);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertForbidden();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->getJson('/api/purchasing')->assertForbidden();
        $this->get('/purchase-orders/'.$id.'/report')->assertForbidden();
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery())->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => 'Unauthorized'])->assertForbidden();
    }

    public function test_legacy_approved_draft_can_receive_without_rewriting_imported_ids(): void
    {
        $id = $this->requestOrder();$this->approve($id);
        DB::table('item_requests')->where('id', $id)->update(['status' => 'Approved', 'poId' => 'LEGACY-PO']);
        DB::table('purchase_orders')->where('id', $id)->update(['id' => 'LEGACY-PO', 'status' => 'Draft']);
        DB::table('purchase_order_lines')->where('poId', $id)->update(['poId' => 'LEGACY-PO']);
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/LEGACY-PO/receive', $this->delivery())->assertCreated()->assertJsonPath('poId', 'LEGACY-PO');
        $this->assertDatabaseHas('purchase_orders', ['id' => 'LEGACY-PO', 'itemRequestId' => $id]);
        $this->get('/purchase-orders/LEGACY-PO/report')->assertOk()->assertSee('Legacy request reference: '.$id);
    }

    public function test_admin_removal_is_saved_and_historical_report_uses_snapshots(): void
    {
        DB::table('products')->insert(['id' => 100, 'name' => 'Second Item', 'category' => 'Supplies', 'stock' => 0, 'status' => 'Active', 'unit' => 'Piece', 'stockUnit' => 'Piece', 'unitPrice' => 1, 'supplierId' => $this->supplier]);
        $id = $this->postJson('/api/purchase-requests', ['category' => 'Supplies', 'lines' => [['productId' => $this->product, 'qty' => 2], ['productId' => 100, 'qty' => 1]]])->assertCreated()->json('id');
        $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id, ['action' => 'modify', 'revision' => 0, 'lines' => [['productId' => $this->product, 'qty' => 2]]])->assertOk();
        $this->assertDatabaseMissing('item_request_lines', ['requestId' => $id, 'productId' => 100]);
        $this->patchJson('/api/purchase-requests/'.$id, ['action' => 'approved', 'revision' => 1])->assertOk();
        $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(2))->assertCreated();
        DB::table('products')->where('id', $this->product)->update(['name' => 'Renamed Item']);
        DB::table('suppliers')->where('id', $this->supplier)->update(['name' => 'Renamed Supplier']);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('New Item')->assertSee('Purchase Supplier')->assertDontSee('Renamed Item')->assertDontSee('Second Item');
    }

    public function test_po_numbers_are_allocated_in_database_and_retries_return_original_request(): void
    {
        $body = ['submissionKey' => (string) Str::uuid(), 'category' => 'Supplies', 'lines' => [['productId' => $this->product, 'qty' => 2]]];
        $first = $this->postJson('/api/purchase-requests', $body)->assertCreated()->json('id');
        $this->postJson('/api/purchase-requests', $body)->assertOk()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('item_requests', 1);
        $changed = $body;$changed['lines'][0]['qty'] = 3;
        $this->postJson('/api/purchase-requests', $changed)->assertConflict();
        $second = $this->requestOrder();
        $this->assertSame('PO-'.now()->format('Y').'-000001', $first);
        $this->assertSame('PO-'.now()->format('Y').'-000002', $second);
        $this->assertDatabaseHas('inventory_sequences', ['name' => 'purchase_orders', 'lastValue' => 2]);
        $this->getJson('/api/purchasing')->assertOk()->assertJsonCount(2, 'requests');
        $this->postJson('/api/purchase-requests', $body + ['id' => 'PO-MANUAL'])->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_po_format_preserves_legacy_ids_and_continues_sequence_across_years(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31)->startOfDay());
        DB::table('item_requests')->insert(['id' => '42', 'status' => 'Pending Approval']);
        DB::table('inventory_sequences')->where('name', 'purchase_orders')->update(['lastValue' => 42]);
        $this->assertSame('PO-2026-000043', $this->requestOrder());
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame('PO-2027-000044', $this->requestOrder());
        $this->assertDatabaseHas('item_requests', ['id' => '42']);
        $this->travelBack();
    }

    public function test_po_number_skips_an_existing_formatted_id(): void
    {
        $first = $this->requestOrder();
        DB::table('inventory_sequences')->where('name', 'purchase_orders')->update(['lastValue' => 0]);
        $second = $this->requestOrder();
        $this->assertNotSame($first, $second);
        $this->assertSame('PO-'.now()->format('Y').'-000002', $second);
    }

    public function test_five_missing_are_reported_while_received_items_post_and_history_is_preserved(): void
    {
        $id = $this->requestOrder(20);
        $this->approve($id);
        $receipt = $this->actingAs($this->manager)->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(15))
            ->assertCreated()->assertJsonPath('inspection.0.missing', 5)->assertJsonPath('inspection.0.received', 15)->json('id');
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 15]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'status' => 'Partially Received']);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('MISSING ITEM / SHORTAGE REPORT');
        $notification = DB::table('notifications')->where('recipientId', $this->admin->id)->where('recipientRole', 'admin')->where('type', 'Stock received')->value('message');
        $this->assertStringContainsString('5 Piece missing', $notification);
        $this->postJson('/api/purchase-orders/'.$id.'/receive', $this->delivery(5, 1))->assertCreated()->assertJsonPath('inspection.0.missing', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product, 'stock' => 20]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'status' => 'Fully Received']);
        $snapshot = json_decode(DB::table('receiving_records')->where('id', $receipt)->value('inspection'), true);
        $this->assertSame(5, $snapshot[0]['missing']);
        $this->get('/purchase-orders/'.$id.'/report')->assertOk()->assertSee('MISSING ITEM / SHORTAGE REPORT')->assertSee('RECEIVING INSPECTION REPORT');
    }
}
