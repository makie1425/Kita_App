<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspacePurchasingReportTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $name): User
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => $name]);
        $workspaceId = DB::table('workspaces')->insertGetId(['organization_id' => $organizationId, 'name' => $name]);
        $manager = User::factory()->create(['role' => 'manager', 'workspace_id' => $workspaceId]);
        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId, 'user_id' => $manager->id,
            'user_role' => 'manager', 'active' => true, 'can_manage_accounts' => true,
        ]);

        return $manager;
    }

    public function test_purchasing_reports_only_include_the_active_workspace(): void
    {
        $owner = $this->manager('Owner');
        $other = $this->manager('Other');
        DB::table('purchase_orders')->insert([
            'id' => 'PO-OWNER', 'workspace_id' => $owner->workspace_id,
            'created' => now()->toDateString(), 'status' => 'Approved',
        ]);
        DB::table('purchase_order_lines')->insert([
            'poId' => 'PO-OWNER', 'workspace_id' => $owner->workspace_id,
            'name' => 'Private item', 'orderedQty' => 5, 'deliveredQty' => 2, 'unitCost' => 10,
        ]);
        DB::table('receiving_records')->insert([
            'id' => 'RCV-OWNER', 'poId' => 'PO-OWNER', 'workspace_id' => $owner->workspace_id,
            'date' => now()->toDateString(), 'supplierName' => 'Private supplier',
        ]);
        DB::table('receiving_record_lines')->insert([
            'receivingId' => 'RCV-OWNER', 'workspace_id' => $owner->workspace_id,
            'name' => 'Private item', 'deliveredQty' => 2, 'unitCost' => 10,
        ]);

        foreach (['outstanding_orders', 'purchase_orders', 'purchases', 'supplier_book', 'payables'] as $type) {
            $this->actingAs($owner)->getJson('/reports/data?type='.$type)->assertOk()->assertJsonCount(1, 'rows');
            $this->actingAs($other)->getJson('/reports/data?type='.$type)->assertOk()->assertJsonCount(0, 'rows');
        }
    }
}
