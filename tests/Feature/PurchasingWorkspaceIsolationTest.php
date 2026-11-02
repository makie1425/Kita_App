<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReceivingCarryover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasingWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $name): User
    {
        $organization = DB::table('organizations')->insertGetId(['name' => $name]);
        $workspace = DB::table('workspaces')->insertGetId(['organization_id' => $organization, 'name' => $name]);
        $manager = User::factory()->create(['role' => 'manager', 'workspace_id' => $workspace]);
        DB::table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $manager->id, 'user_role' => 'manager', 'active' => true, 'can_manage_accounts' => true]);

        return $manager;
    }

    public function test_foreign_purchase_order_cannot_be_received_or_planned(): void
    {
        config(['workspaces.enabled' => true]);
        $owner = $this->manager('Owner');
        $other = $this->manager('Other');
        DB::table('purchase_orders')->insert(['id' => 'PO-FOREIGN', 'workspace_id' => $owner->workspace_id]);

        $this->actingAs($other)->patchJson('/api/purchase-orders/PO-FOREIGN/plan', ['expectedDeliveryDate' => '2026-11-10'])->assertNotFound();
        $this->postJson('/api/purchase-orders/PO-FOREIGN/receive', [
            'idempotencyKey' => 'fc711794-5742-4e9c-ad69-cb4d4c7b02aa',
            'version' => 0,
            'receivedDate' => now()->toDateString(),
            'lines' => [['productId' => 1, 'qty' => 1]],
        ])->assertNotFound();
        $this->assertDatabaseMissing('receiving_records', ['poId' => 'PO-FOREIGN']);
    }

    public function test_foreign_stock_receipt_report_is_hidden(): void
    {
        config(['workspaces.enabled' => true]);
        $owner = $this->manager('Owner');
        $other = $this->manager('Other');
        DB::table('stock_receipts')->insert([
            'id' => 'SR-FOREIGN', 'requestKey' => '84db1f40-eacc-46c1-84eb-39f91e13ff9b',
            'payloadHash' => str_repeat('a', 64), 'supplierId' => 1,
            'deliveryDate' => now()->toDateString(), 'status' => 'Ready',
            'payload' => '{}', 'summary' => '[]', 'exceptions' => '[]',
            'requestedBy' => $owner->id, 'workspace_id' => $owner->workspace_id,
        ]);

        $this->actingAs($other)->get('/stock-receipts/SR-FOREIGN/report')->assertNotFound();
    }

    public function test_carryover_reads_and_release_ignore_foreign_workspace(): void
    {
        config(['workspaces.enabled' => true]);
        $owner = $this->manager('Owner');
        $other = $this->manager('Other');
        DB::table('purchase_orders')->insert([
            ['id' => 'PO-SOURCE', 'workspace_id' => $owner->workspace_id],
            ['id' => 'PO-TARGET', 'workspace_id' => $owner->workspace_id],
        ]);
        $sourceLine = DB::table('purchase_order_lines')->insertGetId(['poId' => 'PO-SOURCE', 'orderedQty' => 5, 'deliveredQty' => 0, 'workspace_id' => $owner->workspace_id]);
        $targetLine = DB::table('purchase_order_lines')->insertGetId(['poId' => 'PO-TARGET', 'orderedQty' => 5, 'deliveredQty' => 0, 'workspace_id' => $owner->workspace_id]);
        $carryoverId = DB::table('receiving_carryovers')->insertGetId([
            'requestKey' => 'a5dd2594-5c41-4d8d-90a1-d30ac6dffcb3',
            'sourcePo' => 'PO-SOURCE', 'sourceLine' => $sourceLine,
            'targetPo' => 'PO-TARGET', 'targetLine' => $targetLine,
            'quantity' => 3, 'actorId' => $owner->id,
            'created_at' => now(), 'workspace_id' => $owner->workspace_id,
        ]);

        $this->actingAs($other);
        $this->assertSame(0, ReceivingCarryover::reserved($sourceLine));
        $this->assertSame(5, ReceivingCarryover::originalRemaining((object) ['id' => $targetLine, 'orderedQty' => 5, 'deliveredQty' => 0]));
        $this->postJson('/api/receiving-carryovers/'.$carryoverId.'/release')->assertNotFound();
        $this->assertDatabaseHas('receiving_carryovers', ['id' => $carryoverId, 'releasedQty' => 0]);
    }

    public function test_carryover_cannot_allocate_to_foreign_purchase_order(): void
    {
        config(['workspaces.enabled' => true]);
        $owner = $this->manager('Owner');
        $other = $this->manager('Other');
        DB::table('purchase_orders')->insert([
            ['id' => 'PO-OWN', 'workspace_id' => $owner->workspace_id],
            ['id' => 'PO-FOREIGN', 'workspace_id' => $other->workspace_id],
        ]);
        $sourceLine = DB::table('purchase_order_lines')->insertGetId(['poId' => 'PO-OWN', 'orderedQty' => 5, 'deliveredQty' => 0, 'workspace_id' => $owner->workspace_id]);

        $this->actingAs($owner)->postJson('/api/purchase-orders/PO-OWN/carryovers', [
            'requestKey' => 'e2cb8959-17cd-4a3f-bc6d-d70835db08df',
            'sourceLine' => $sourceLine, 'targetPo' => 'PO-FOREIGN', 'quantity' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('receiving_carryovers', 0);
    }
}
