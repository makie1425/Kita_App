<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FifoInventory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellableStockTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-13 12:00:00'));
        $this->manager = User::factory()->create(['role' => 'manager', 'approval_pin' => Hash::make('1234')]);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->manager);
        DB::table('categories')->insert(['name' => 'Food', 'status' => 'Active']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Milk', 'category' => 'Food', 'status' => 'Active', 'stock' => 0, 'price' => 20, 'unitPrice' => 10]);
        Http::preventStrayRequests();
    }

    private function batch(int $qty, ?string $expiry = null, bool $recalled = false, string $received = '2026-10-01', int $cost = 10): int
    {
        FifoInventory::receive(1, $qty, $cost, $received, batch: 'B-'.Str::random(5), expiry: $expiry);
        $id = (int) DB::table('inventory_batches')->max('id');
        DB::table('inventory_batches')->where('id', $id)->update(['recalled' => $recalled]);
        DB::table('products')->where('id', 1)->increment('stock', $qty);

        return $id;
    }

    private function event(int $batch, string $type, int $quantity, ?string $key = null)
    {
        return $this->postJson('/api/inventory/workflows/events', ['requestKey' => $key ?? (string) Str::uuid(), 'batchId' => $batch, 'type' => $type, 'quantity' => $quantity, 'reason' => 'Verified by manager']);
    }

    private function sale(int $quantity, string $uuid = 'STOCK-SALE')
    {
        return $this->postJson('/api/transactions', ['uuid' => $uuid, 'items' => [['productId' => 1, 'qty' => $quantity]], 'paymentMode' => 'Cash', 'tendered' => 1000]);
    }

    public function test_mixed_batches_have_exact_physical_and_sellable_totals_without_double_counting(): void
    {
        $this->batch(3, '2026-10-20');
        $this->batch(2, '2026-10-12');
        $this->batch(4, '2026-10-20', true);
        $this->batch(1, '2026-10-12', true);
        $this->batch(2);
        $this->getJson('/api/inventory/availability')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('products.0.stockOnHand', 12)->assertJsonPath('products.0.sellableStock', 5)
            ->assertJsonPath('products.0.expiredStock', 3)->assertJsonPath('products.0.recalledStock', 5)
            ->assertJsonPath('products.0.expiredRecalledStock', 1)->assertJsonPath('products.0.unavailableStock', 7);
        $this->getJson('/api/inventory-history')->assertOk()->assertJsonPath('products.0.stockOnHand', 12)->assertJsonPath('products.0.sellableStock', 5);
        $this->getJson('/reports/data?type=inventory')->assertOk()->assertJsonPath('rows.0.stockOnHand', 12)->assertJsonPath('rows.0.sellableStock', 5)->assertJsonPath('rows.0.recalledStock', 5);
    }

    public function test_recall_release_and_disposal_preserve_physical_stock_until_actual_removal(): void
    {
        $id = $this->batch(5, '2026-10-20');
        $key = (string) Str::uuid();
        $this->event($id, 'Recall', 5, $key)->assertCreated();
        $this->event($id, 'Recall', 5, $key)->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 5]);
        $this->assertDatabaseCount('inventory_allocations', 0);
        $this->assertDatabaseHas('stock_movements', ['referenceType' => 'recall', 'quantityChange' => 0]);
        $this->getJson('/api/inventory/availability?productId=1&quantity=1')->assertUnprocessable()->assertSee('Recalled');
        $this->sale(1)->assertUnprocessable();
        $this->event($id, 'Release recall', 5)->assertCreated();
        $this->getJson('/api/inventory/availability?productId=1&quantity=5')->assertOk();
        $this->event($id, 'Recall', 5)->assertCreated();
        $this->event($id, 'Disposal', 2)->assertCreated();
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.stockOnHand', 3)->assertJsonPath('products.0.recalledStock', 3)->assertJsonPath('products.0.sellableStock', 0);
        $this->travel(8)->days();
        $this->event($id, 'Release recall', 3)->assertCreated();
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.sellableStock', 0)->assertJsonPath('products.0.expiredStock', 3);
    }

    public function test_checkout_rechecks_after_selection_and_fifo_skips_ineligible_batches(): void
    {
        $this->batch(3, '2026-10-12');
        $this->batch(3, '2026-11-01', true);
        $older = $this->batch(2, '2026-11-01', false, '2026-10-02', 9);
        $newer = $this->batch(3, '2026-10-30', false, '2026-10-03', 12);
        $this->actingAs($this->cashier)->getJson('/api/inventory/availability?productId=1&quantity=5')->assertOk();
        $this->actingAs($this->manager);
        $this->event($newer, 'Recall', 3)->assertCreated();
        $this->actingAs($this->cashier);
        $this->sale(5)->assertUnprocessable();
        $this->assertDatabaseCount('transactions', 0);
        $this->actingAs($this->manager);
        $this->event($newer, 'Release recall', 3)->assertCreated();
        $this->sale(4)->assertCreated();
        $this->assertDatabaseHas('inventory_allocations', ['batchId' => $older, 'quantity' => 2, 'unitCost' => 9]);
        $this->assertDatabaseHas('inventory_allocations', ['batchId' => $newer, 'quantity' => 2, 'unitCost' => 12]);
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.stockOnHand', 7)->assertJsonPath('products.0.sellableStock', 1);
        $this->sale(2, 'SECOND')->assertUnprocessable();
    }

    public function test_expiry_rollover_and_return_keep_goods_physical_but_not_sellable(): void
    {
        $this->batch(4, '2026-10-14');
        $this->sale(2)->assertCreated();
        $this->travel(1)->days();
        $this->getJson('/api/inventory/availability?productId=1&quantity=1')->assertUnprocessable()->assertSee('Expired');
        $this->patchJson('/api/transactions/STOCK-SALE', ['action' => 'refund', 'restock' => true, 'reason' => 'Physically returned to quarantine', 'manager_id' => $this->manager->id, 'manager_pin' => '1234'])->assertOk();
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.stockOnHand', 4)->assertJsonPath('products.0.sellableStock', 0)->assertJsonPath('products.0.expiredStock', 4);
    }

    public function test_inactive_category_mismatched_balances_and_manual_quantities_fail_closed(): void
    {
        $this->batch(2);
        foreach ([0, -1, 1.5, 3] as $quantity) {
            $this->getJson('/api/inventory/availability?productId=1&quantity='.$quantity)->assertUnprocessable();
        }
        DB::table('categories')->where('name', 'Food')->update(['status' => 'Inactive']);
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.sellableStock', 0);
        $this->sale(1)->assertUnprocessable();
        DB::table('categories')->where('name', 'Food')->update(['status' => 'Active']);
        DB::table('products')->where('id', 1)->update(['stock' => 7]);
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.stockOnHand', 2)->assertJsonPath('products.0.stockMismatch', true)->assertJsonPath('products.0.sellableStock', 0);
        $this->sale(1)->assertUnprocessable();
    }

    public function test_pending_wallet_stock_is_physically_present_and_recall_blocks_late_payment_confirmation(): void
    {
        config(['services.paymongo.secret' => 'sk_test_example', 'services.paymongo.webhook_secret' => 'secret']);
        $batch = $this->batch(2, '2026-10-20');
        Http::fake(['*/checkout_sessions' => Http::response(['data' => ['id' => 'cs_test', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/test']]])]);
        $this->postJson('/api/payments/paymongo/checkout', ['uuid' => 'WALLET', 'provider' => 'GCash', 'stockItems' => [['productId' => 1, 'qty' => 2]]])->assertOk();
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.stockOnHand', 2)->assertJsonPath('products.0.reservedStock', 2)->assertJsonPath('products.0.sellableStock', 0);
        $this->postJson('/api/inventory/workflows/counts', ['mode' => 'Full'])->assertConflict();
        $this->event($batch, 'Recall', 0)->assertCreated();
        $this->getJson('/api/inventory/availability')->assertJsonPath('products.0.recalledStock', 2);
        $session = ['id' => 'cs_test', 'attributes' => ['metadata' => ['transaction_uuid' => 'WALLET'], 'livemode' => false, 'payments' => [['id' => 'pay_test', 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => 4000]]]]];
        Http::fake(['*/checkout_sessions/cs_test' => Http::response(['data' => $session])]);
        $raw = json_encode(['data' => ['type' => 'checkout_session.payment.paid', 'data' => $session]]);
        $timestamp = time();
        $this->call('POST', '/api/payments/paymongo/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$raw, 'secret')], $raw)->assertConflict();
        $this->assertDatabaseCount('sales_log', 0);
        $this->assertDatabaseHas('transactions', ['uuid' => 'WALLET', 'status' => 'Pending Payment']);
    }
}
