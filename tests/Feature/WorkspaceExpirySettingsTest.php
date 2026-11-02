<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExpiryMonitoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceExpirySettingsTest extends TestCase
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

    public function test_expiry_window_is_private_and_legacy_setting_is_unchanged(): void
    {
        config(['workspaces.enabled' => true]);
        $first = $this->manager('First');
        $second = $this->manager('Second');

        $this->actingAs($first);
        $this->assertSame(7, ExpiryMonitoring::days());
        $this->patchJson('/api/inventory/expiry-settings', ['days' => 14])->assertOk();
        $this->assertSame(14, ExpiryMonitoring::days());
        $this->assertDatabaseHas('workspace_expiry_settings', ['workspace_id' => $first->workspace_id, 'days' => 14]);

        $this->actingAs($second);
        $this->assertSame(7, ExpiryMonitoring::days());
        $this->patchJson('/api/inventory/expiry-settings', ['days' => 30])->assertOk();
        $this->assertSame(30, ExpiryMonitoring::days());
        $this->assertDatabaseHas('workspace_expiry_settings', ['workspace_id' => $first->workspace_id, 'days' => 14]);
        $this->assertDatabaseHas('inventory_settings', ['name' => 'expiry_days', 'value' => 7]);

        DB::table('workspace_memberships')->where('user_id', $second->id)->update(['active' => false]);
        $this->patchJson('/api/inventory/expiry-settings', ['days' => 90])->assertForbidden();
        $this->assertSame(7, ExpiryMonitoring::days());
    }

    public function test_scheduled_notifications_use_valid_recipient_and_product_workspaces(): void
    {
        $first = $this->manager('First');
        $second = $this->manager('Second');
        foreach ([$first, $second] as $manager) {
            DB::table('products')->insert([
                'id' => $manager->workspace_id, 'workspace_id' => $manager->workspace_id,
                'name' => 'Product '.$manager->id, 'stock' => 1, 'status' => 'Active', 'price' => 10,
            ]);
            DB::table('inventory_batches')->insert([
                'productId' => $manager->workspace_id, 'workspace_id' => $manager->workspace_id,
                'quantityReceived' => 1, 'quantityRemaining' => 1, 'unitCost' => 5,
                'receivedDate' => now()->toDateString(), 'expiryDate' => now()->addDays(3)->toDateString(),
                'source' => 'receiving', 'created_at' => now(),
            ]);
        }
        $this->assertSame(2, ExpiryMonitoring::notify());
        $this->assertSame(0, ExpiryMonitoring::notify());
        $this->assertDatabaseHas('notifications', ['recipientId' => $first->id, 'workspace_id' => $first->workspace_id]);
        $this->assertDatabaseHas('notifications', ['recipientId' => $second->id, 'workspace_id' => $second->workspace_id]);
        $this->assertDatabaseCount('notifications', 2);

        DB::table('workspace_memberships')->where('user_id', $second->id)->update(['active' => false]);
        DB::table('notifications')->delete();
        $this->assertSame(1, ExpiryMonitoring::notify());
        $this->assertDatabaseMissing('notifications', ['recipientId' => $second->id]);
    }
}
