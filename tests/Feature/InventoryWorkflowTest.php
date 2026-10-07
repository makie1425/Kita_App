<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FifoInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        DB::table('categories')->insert(['name' => 'Test', 'status' => 'Active']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Test item', 'category' => 'Test', 'status' => 'Active', 'stock' => 10, 'unitPrice' => 5, 'price' => 10, 'stockUnit' => 'Piece']);
        FifoInventory::receive(1, 10, 5, now()->toDateString(), batch: 'B1', expiry: now()->addDays(5)->toDateString());
        $this->batch = DB::table('inventory_batches')->value('id');
    }

    private function event(array $extra = []): array
    {
        return array_replace(['requestKey' => (string) Str::uuid(), 'batchId' => $this->batch, 'quantity' => 2, 'type' => 'Write-off', 'reason' => 'Damaged'], $extra);
    }

    public function test_batch_actions_are_atomic_idempotent_and_validated(): void
    {
        $d = $this->event();
        $this->postJson('/api/inventory/workflows/events', $d)->assertCreated();
        $this->postJson('/api/inventory/workflows/events', $d)->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 8]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $this->batch, 'quantityRemaining' => 8]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->postJson('/api/inventory/workflows/events', $this->event(['quantity' => 9]))->assertUnprocessable();
        $this->postJson('/api/inventory/workflows/events', $this->event(['type' => 'Transfer']))->assertUnprocessable();
        $this->postJson('/api/inventory/workflows/events', $this->event(['type' => 'Transfer', 'destination' => 'Warehouse B']))->assertCreated();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 6]);
        $this->getJson('/api/inventory/workflows')->assertOk()->assertJsonCount(2, 'events')->assertJsonPath('batches.0.batchNumber', 'B1');
    }

    public function test_recall_requires_entire_batch(): void
    {
        $this->postJson('/api/inventory/workflows/events', $this->event(['type' => 'Recall']))->assertUnprocessable();
        $this->postJson('/api/inventory/workflows/events', $this->event(['type' => 'Recall', 'quantity' => 10]))->assertCreated();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 0]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $this->batch, 'recalled' => true, 'quantityRemaining' => 0]);
    }

    public function test_counts_reconcile_surplus_and_shortage_and_hide_blind_expected(): void
    {
        $id = $this->postJson('/api/inventory/workflows/counts', ['mode' => 'Blind'])->assertCreated()->json('id');
        $this->getJson('/api/inventory/workflows')->assertOk()->assertJsonMissingPath('counts.0.lines.0.expected');
        $this->postJson('/api/inventory/workflows/counts', ['mode' => 'Full'])->assertConflict();
        $payload = ['action' => 'Complete', 'reason' => 'Verified physical count', 'lines' => [['productId' => 1, 'counted' => 12]]];
        $this->postJson('/api/inventory/workflows/counts/'.$id, $payload)->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 12]);
        $this->assertDatabaseHas('inventory_batches', ['source' => 'count_surplus', 'quantityRemaining' => 2]);
        $this->postJson('/api/inventory/workflows/counts/'.$id, $payload)->assertConflict();
        $id = $this->postJson('/api/inventory/workflows/counts', ['mode' => 'Cycle', 'category' => 'Test'])->assertCreated()->json('id');
        $payload['lines'][0]['counted'] = 7;
        $this->postJson('/api/inventory/workflows/counts/'.$id, $payload)->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 7]);
        $this->assertEquals(7, DB::table('inventory_batches')->sum('quantityRemaining'));
    }

    public function test_recalled_batch_cannot_be_restocked_by_a_refund(): void
    {
        DB::transaction(function () {
            FifoInventory::consume(1, 2, 'checkout', 'SALE-1');
            DB::table('products')->where('id', 1)->update(['stock' => 8]);
        });
        $this->postJson('/api/inventory/workflows/events', $this->event(['type' => 'Recall', 'quantity' => 8]))->assertCreated();
        try {
            DB::transaction(fn () => FifoInventory::returnStock(1, 2, 'SALE-1'));
            $this->fail('Recalled stock must not be restocked.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Recalled stock', $e->getMessage());
        }
        $this->assertDatabaseHas('inventory_batches', ['id' => $this->batch, 'quantityRemaining' => 0]);
    }

    public function test_movement_requires_restart_and_cancel_preserves_stock(): void
    {
        $id = $this->postJson('/api/inventory/workflows/counts', ['mode' => 'Full'])->assertCreated()->json('id');
        $this->postJson('/api/inventory/workflows/events', $this->event())->assertCreated();
        $this->postJson('/api/inventory/workflows/counts/'.$id, ['action' => 'Complete', 'reason' => 'Counted', 'lines' => [['productId' => 1, 'counted' => 10]]])->assertConflict();
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 8]);
        $this->postJson('/api/inventory/workflows/counts/'.$id, ['action' => 'Cancel', 'reason' => 'Restart'])->assertOk();
    }

    public function test_archive_persists_and_cashiers_are_denied(): void
    {
        $this->postJson('/api/inventory/workflows/archive', ['kind' => 'products', 'ids' => ['1']])->assertOk();
        $this->assertDatabaseHas('products', ['id' => 1, 'status' => 'Inactive', 'archivedAt' => now()->toDateString()]);
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->getJson('/api/inventory/workflows')->assertForbidden();
        $this->postJson('/api/inventory/workflows/events',$this->event())->assertForbidden();
        $this->postJson('/api/inventory/workflows/counts',['mode' => 'Full'])->assertForbidden();
    }
}
