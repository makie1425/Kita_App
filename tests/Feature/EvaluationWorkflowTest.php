<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExpiryMonitoring;
use App\Services\FifoInventory;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EvaluationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12 10:00:00'));
        $this->manager = User::factory()->create(['role' => 'manager', 'approval_pin' => Hash::make('1234')]);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        DB::table('categories')->insert(['name' => 'Food', 'status' => 'Active']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Milk', 'stock' => 0, 'status' => 'Active', 'category' => 'Food', 'price' => 10, 'unitPrice' => 5]);
        $this->actingAs($this->cashier);
    }

    private function batch(int $qty, ?string $expiry, string $date = '2026-10-01', float $cost = 5): int
    {
        FifoInventory::receive(1, $qty, $cost, $date, expiry: $expiry);
        DB::table('products')->where('id', 1)->increment('stock', $qty);

        return (int) DB::table('inventory_batches')->max('id');
    }

    private function sale(string $id = 'SALE', int $qty = 1): array
    {
        return ['uuid' => $id, 'items' => [['productId' => 1, 'qty' => $qty]], 'paymentMode' => 'Cash', 'tendered' => 100];
    }

    private function approval(string $action = 'refund'): array
    {
        return ['action' => $action, 'reason' => 'Customer return', 'manager_id' => $this->manager->id, 'manager_pin' => '1234'];
    }

    public function test_expired_batches_are_excluded_from_sales_and_catalog_but_remain_for_disposal(): void
    {
        $expired = $this->batch(2, '2026-10-11');
        $today = $this->batch(1, '2026-10-12');
        $fresh = $this->batch(2, '2026-10-20', cost: 9);
        $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('PRODUCTS.0.stock', 5)->assertJsonPath('PRODUCTS.0.sellableStock', 2);
        $this->postJson('/api/transactions', $this->sale(qty: 3))->assertUnprocessable();
        $this->assertDatabaseCount('transactions', 0);
        $this->postJson('/api/transactions', $this->sale(qty: 2))->assertCreated();
        $this->assertDatabaseHas('inventory_batches', ['id' => $expired, 'quantityRemaining' => 2]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $today, 'quantityRemaining' => 1]);
        $this->assertDatabaseHas('inventory_allocations', ['batchId' => $fresh, 'quantity' => 2, 'unitCost' => 9]);
        $this->actingAs($this->manager)->postJson('/api/inventory/workflows/events', ['requestKey' => (string) \Illuminate\Support\Str::uuid(), 'batchId' => $expired, 'type' => 'Disposal', 'quantity' => 2, 'reason' => 'Expired'])->assertCreated();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 1]);
    }

    public function test_expired_legacy_stock_cannot_bypass_batch_checks(): void
    {
        DB::table('products')->where('id', 1)->update(['stock' => 4, 'expiry' => '2026-10-11']);
        $this->postJson('/api/transactions', $this->sale())->assertUnprocessable();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_nonrestocked_refund_and_expired_reservation_release_preserve_balances(): void
    {
        $batch = $this->batch(2, '2026-10-13');
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        $this->travel(1)->days();
        $this->patchJson('/api/transactions/SALE', $this->approval() + ['restock' => false])->assertOk();
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch, 'quantityRemaining' => 1]);
        $this->travelBack();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12 10:00:00'));
        DB::transaction(function () {
            DB::table('products')->where('id', 1)->update(['stock' => 0]);
            StockMovement::record(1, 1, 0, 'payment_reservation', 'RESERVED', $this->cashier);
        });
        $this->travel(1)->days();
        DB::transaction(function () {
            DB::table('products')->where('id', 1)->update(['stock' => 1]);
            StockMovement::record(1, 0, 1, 'payment_release', 'RESERVED', $this->cashier);
        });
        $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('PRODUCTS.0.sellableStock', 0);
    }

    public function test_alerts_are_recipient_scoped_deduplicated_and_escalate_on_expiry(): void
    {
        $this->batch(2, '2026-10-19'); // Seven-day boundary.
        $this->batch(2, '2026-10-20'); // Outside the alert window.
        $removed = $this->batch(2, '2026-10-11');
        DB::table('inventory_batches')->where('id', $removed)->update(['quantityRemaining' => 0]);
        $this->assertSame(1, ExpiryMonitoring::notify());
        $this->assertSame(0, ExpiryMonitoring::notify());
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'notifications');
        $this->actingAs($this->manager)->getJson('/api/notifications')->assertJsonCount(1, 'notifications');
        $this->travel(7)->days();
        $this->assertSame(2, ExpiryMonitoring::notify());
        $this->assertDatabaseHas('notifications', ['recipientId' => $this->manager->id, 'type' => 'Expired stock', 'priority' => 'Critical']);
        $this->assertSame(0, ExpiryMonitoring::notify());
    }

    public function test_higher_value_exchange_collects_difference_and_creates_a_refundable_replacement_sale(): void
    {
        $this->batch(2, null);
        DB::table('products')->insert(['id' => 2, 'name' => 'Replacement', 'stock' => 2, 'status' => 'Active', 'category' => 'Food', 'price' => 15, 'unitPrice' => 8]);
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        $body = $this->approval('exchange') + ['replacement_product_id' => 2, 'exchange_tendered' => 4];
        $this->patchJson('/api/transactions/SALE', $body)->assertUnprocessable()->assertJsonValidationErrors('exchange_tendered');
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 1]);
        $body['exchange_tendered'] = 10;
        $result = $this->patchJson('/api/transactions/SALE', $body)->assertOk()->assertJsonPath('exchangeDifference', 5)->assertJsonPath('exchangeChange', 5);
        $replacement = $result->json('replacementTransaction.uuid');
        $this->assertDatabaseHas('transactions', ['uuid' => $replacement, 'total' => 15, 'exchangeCredit' => 10, 'exchangeOriginalUuid' => 'SALE', 'changeAmount' => 5]);
        $this->assertDatabaseHas('transaction_lines', ['transaction_uuid' => $replacement, 'productId' => 2, 'lineTotal' => 15]);
        $this->assertDatabaseHas('transactions', ['uuid' => 'SALE', 'refundedAmount' => 10, 'status' => 'Closed']);
        $this->assertEquals(15, DB::table('sales_log')->sum('amount'));
        $this->patchJson('/api/transactions/SALE', $body)->assertConflict();
        $this->patchJson('/api/transactions/'.$replacement, $this->approval())->assertOk();
        $this->assertDatabaseHas('products', ['id' => 2, 'stock' => 2]);
        $this->assertEquals(0, DB::table('sales_log')->sum('amount'));
    }

    public function test_exchange_rejects_lower_value_wallet_and_expired_replacements_atomically(): void
    {
        $this->batch(1, null);
        DB::table('products')->insert(['id' => 2, 'name' => 'Replacement', 'stock' => 2, 'status' => 'Active', 'category' => 'Food', 'price' => 9, 'unitPrice' => 8, 'expiry' => '2026-10-11']);
        $this->postJson('/api/transactions', $this->sale())->assertCreated();
        $body = $this->approval('exchange') + ['replacement_product_id' => 2];
        $this->patchJson('/api/transactions/SALE', $body)->assertUnprocessable();
        DB::table('products')->where('id', 2)->update(['price' => 10]);
        $this->patchJson('/api/transactions/SALE', $body)->assertUnprocessable();
        DB::table('transactions')->where('uuid', 'SALE')->update(['paymentMode' => 'GCash']);
        $this->patchJson('/api/transactions/SALE', $body)->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_return_lines', 0);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 0]);
    }
}
