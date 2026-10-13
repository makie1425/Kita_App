<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExpiryMonitoring;
use App\Services\FifoInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagerChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
        $this->manager = User::factory()->create(['role' => 'manager', 'approval_pin' => Hash::make('1234')]);
        $this->actingAs($this->manager);
        DB::table('categories')->insert(['name' => 'Food', 'status' => 'Active']);
        DB::table('suppliers')->insert(['id' => 1, 'name' => 'Original supplier', 'status' => 'Active']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Milk', 'category' => 'Food', 'status' => 'Active', 'stock' => 10,
            'price' => 20, 'unitPrice' => 5, 'barcode' => 'MILK', 'stockUnit' => 'Piece', 'purchaseUnit' => 'Box', 'conversionFactor' => 12]);
        FifoInventory::receive(1, 10, 5, '2026-10-10', supplier: 1, batch: 'B1', expiry: '2026-10-23', lot: 'LOT1');
    }

    private function promo(array $extra = []): array
    {
        return array_replace(['name' => 'Milk discount', 'type' => 'Near-Expiry', 'discountPct' => 10,
            'startDate' => '2026-10-13', 'endDate' => '2026-10-20', 'productIds' => [1]], $extra);
    }

    public function test_promotions_persist_reject_overlap_and_apply_saved_discount_at_checkout(): void
    {
        $id = $this->postJson('/api/promotions', $this->promo())->assertCreated()->json('id');
        $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('PROMOTIONS.0.id', $id);
        $this->getJson('/api/inventory/availability')->assertOk()->assertJsonPath('activePromotions.0.id', $id)->assertJsonPath('activePromotions.0.productIds.0', 1);
        $this->postJson('/api/promotions', $this->promo())->assertUnprocessable();
        $this->postJson('/api/promotions', $this->promo(['type' => 'Category-Wide', 'category' => 'Food']))->assertUnprocessable();
        $this->postJson('/api/transactions', ['uuid' => 'PROMO-SALE', 'items' => [['productId' => 1, 'qty' => 1]],
            'paymentMode' => 'Cash', 'tendered' => 20, 'discount_manager_id' => $this->manager->id, 'discount_manager_pin' => '1234'])->assertCreated();
        $this->assertDatabaseHas('transactions', ['uuid' => 'PROMO-SALE', 'total' => 18]);
        $this->assertDatabaseCount('promotions', 1);
    }

    public function test_promotion_validation_and_category_scope_are_server_enforced(): void
    {
        $this->postJson('/api/promotions', $this->promo(['discountPct' => 90]))->assertUnprocessable();
        $this->postJson('/api/promotions', $this->promo(['endDate' => '2026-10-01']))->assertUnprocessable();
        $this->postJson('/api/promotions', $this->promo(['productIds' => []]))->assertUnprocessable();
        $this->postJson('/api/promotions', $this->promo(['type' => 'Category-Wide', 'category' => 'Food', 'productIds' => []]))->assertCreated();
        $this->assertDatabaseHas('promotion_products', ['productId' => 1]);
    }

    public function test_expiry_window_is_saved_and_alerts_remain_deduplicated(): void
    {
        $this->assertSame(0, ExpiryMonitoring::notify($this->manager));
        $this->patchJson('/api/inventory/expiry-settings', ['days' => 14])->assertOk();
        $this->assertSame(14, ExpiryMonitoring::days());
        $this->assertSame(1, ExpiryMonitoring::notify($this->manager));
        $this->assertSame(0, ExpiryMonitoring::notify($this->manager));
        $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('EXPIRY_ALERT_DAYS', 14)->assertJsonCount(1, 'EXPIRING_BATCHES');
        $this->patchJson('/api/inventory/expiry-settings', ['days' => 366])->assertUnprocessable();
        $this->patchJson('/api/inventory/expiry-settings', ['days' => -1])->assertUnprocessable();
    }

    public function test_evidence_is_validated_saved_and_only_available_to_authorized_roles(): void
    {
        $body = ['productId' => 1, 'qtyChange' => -1, 'reason' => 'Damaged', 'comment' => 'Broken packaging'];
        $this->postJson('/api/inventory/adjustments', $body + ['evidence' => 'data:image/png;base64,bm90LWFuLWltYWdl'])->assertUnprocessable();
        $image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1cAAAAASUVORK5CYII=';
        $id = $this->postJson('/api/inventory/adjustments', $body + ['evidence' => 'data:image/png;base64,'.$image])->assertCreated()->json('id');
        $this->assertDatabaseHas('adjustments', ['id' => $id, 'photo' => true]);
        $this->get('/api/inventory/adjustments/'.$id.'/evidence')->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent(base64_decode($image));
        $fake = $this->postJson('/api/inventory/adjustments', $body + ['photo' => true])->assertCreated()->json('id');
        $this->assertDatabaseHas('adjustments', ['id' => $fake, 'photo' => false]);
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->getJson('/api/inventory/adjustments/'.$id.'/evidence')->assertForbidden();
    }

    public function test_supplier_return_deducts_exact_batch_and_retry_cannot_duplicate(): void
    {
        $batch = DB::table('inventory_batches')->value('id');
        $body = ['requestKey' => (string) Str::uuid(), 'batchId' => $batch, 'type' => 'Supplier return', 'quantity' => 2, 'reason' => 'Supplier agreed to take damaged stock'];
        $this->postJson('/api/inventory/workflows/events', $body)->assertCreated();
        $this->postJson('/api/inventory/workflows/events', $body)->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 8]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'quantityRemaining' => 8]);
        $this->assertDatabaseHas('inventory_events', ['type' => 'Supplier return', 'destination' => 'Original supplier']);
        $this->postJson('/api/inventory/workflows/events', array_replace($body, ['requestKey' => (string) Str::uuid(), 'quantity' => 9]))->assertUnprocessable();
        DB::table('inventory_batches')->where('id', $batch)->update(['supplierId' => null]);
        $this->postJson('/api/inventory/workflows/events', array_replace($body, ['requestKey' => (string) Str::uuid()]))->assertUnprocessable();
    }

    public function test_recall_trace_finds_lot_inventory_and_original_sale_allocations(): void
    {
        $this->postJson('/api/transactions', ['uuid' => 'TRACE-SALE', 'items' => [['productId' => 1, 'qty' => 2]], 'paymentMode' => 'Cash', 'tendered' => 40])->assertCreated();
        $this->getJson('/api/inventory/recall-trace?search=LOT1')->assertOk()->assertJsonPath('batches.0.stockOnHand', 8)
            ->assertJsonPath('sales.0.uuid', 'TRACE-SALE')->assertJsonPath('sales.0.quantity', 2);
        $this->getJson('/api/inventory/recall-trace?search=UNKNOWN')->assertOk()->assertJsonCount(0, 'batches')->assertJsonCount(0, 'sales');
        $this->getJson('/api/inventory/recall-trace')->assertUnprocessable();
    }

    public function test_supplier_contact_history_is_persistent_idempotent_and_dated_after_receipt(): void
    {
        DB::table('purchase_orders')->insert(['id' => 'PO1', 'supplierId' => 1]);
        DB::table('receiving_records')->insert(['id' => 'R1', 'poId' => 'PO1', 'date' => '2026-10-12', 'discrepancy' => true]);
        $body = ['requestKey' => (string) Str::uuid(), 'contactDate' => '2026-10-13', 'person' => 'Supplier agent', 'channel' => 'Phone', 'notes' => 'Two units missing', 'outcome' => 'Replacement tomorrow'];
        $this->postJson('/api/receipts/R1/supplier-contacts', $body)->assertCreated();
        $this->postJson('/api/receipts/R1/supplier-contacts', $body)->assertOk();
        $this->postJson('/api/receipts/R1/supplier-contacts', array_replace($body, ['outcome' => 'Different']))->assertConflict();
        $this->assertDatabaseCount('supplier_contacts', 1);
        $this->assertDatabaseHas('supplier_contacts', ['receiptId' => 'R1', 'supplierId' => 1, 'outcome' => 'Replacement tomorrow']);
        $this->postJson('/api/receipts/R1/supplier-contacts', array_replace($body, ['requestKey' => (string) Str::uuid(), 'contactDate' => '2026-10-11']))->assertUnprocessable();
    }

    public function test_override_decline_requires_pin_records_reason_and_does_not_create_sale(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $body = ['requestKey' => (string) Str::uuid(), 'manager_id' => $this->manager->id, 'manager_pin' => '0000',
            'reason' => 'Price is correct', 'items' => [['productId' => 1, 'qty' => 2]]];
        $this->postJson('/api/price-overrides/decline', $body)->assertUnprocessable();
        $body['manager_pin'] = '1234';
        $this->postJson('/api/price-overrides/decline', $body)->assertCreated();
        $this->postJson('/api/price-overrides/decline', $body)->assertOk();
        $this->assertDatabaseCount('override_decisions', 1);
        $this->assertDatabaseHas('override_decisions', ['decision' => 'Declined', 'reason' => 'Price is correct']);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 10]);
    }

    public function test_variants_have_separate_barcodes_and_prevent_duplicates_and_cycles(): void
    {
        $body = ['name' => 'Milk', 'category' => 'Food', 'barcode' => 'MILK-VANILLA', 'unitPrice' => 5, 'price' => 20,
            'purchaseUnit' => 'Box', 'stockUnit' => 'Piece', 'conversionFactor' => 12, 'parentId' => 1, 'variantLabel' => 'Vanilla'];
        $id = $this->postJson('/api/products', $body)->assertCreated()->json('product.id');
        $this->assertDatabaseHas('products', ['id' => $id, 'parentId' => 1, 'variantLabel' => 'Vanilla', 'stock' => 0]);
        $this->postJson('/api/products', array_replace($body, ['barcode' => 'OTHER', 'variantLabel' => ' vanilla ']))->assertUnprocessable();
        $this->postJson('/api/products', array_replace($body, ['barcode' => 'OTHER', 'parentId' => $id]))->assertUnprocessable();
        $this->patchJson('/api/products/'.$id, array_replace($body, ['parentId' => $id]))->assertUnprocessable();
        $this->postJson('/api/products', array_replace($body, ['barcode' => 'OTHER', 'variantLabel' => '']))->assertUnprocessable();
    }

    public function test_cashier_cannot_modify_manager_workflows(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->postJson('/api/promotions', $this->promo())->assertForbidden();
        $this->patchJson('/api/inventory/expiry-settings', ['days' => 10])->assertForbidden();
        $this->getJson('/api/inventory/recall-trace?search=LOT1')->assertForbidden();
        $this->postJson('/api/receipts/R1/supplier-contacts', [])->assertForbidden();
        $this->assertDatabaseCount('promotions', 0);
    }
}
