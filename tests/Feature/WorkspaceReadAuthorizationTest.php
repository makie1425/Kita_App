<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkspaceRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceReadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_user_read_contains_only_admins_in_its_workspace(): void
    {
        config(['workspaces.enabled' => true]);
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'A']);
        $workspaceId = DB::table('workspaces')->insertGetId(['organization_id' => $organizationId, 'name' => 'A']);
        $superAdmin = User::factory()->create(['role' => 'superadmin', 'workspace_id' => $workspaceId]);
        $admin = User::factory()->create(['role' => 'admin', 'workspace_id' => $workspaceId]);
        User::factory()->create(['role' => 'cashier', 'workspace_id' => $workspaceId]);
        User::factory()->create(['role' => 'manager', 'workspace_id' => $workspaceId]);
        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId, 'user_id' => $superAdmin->id,
            'user_role' => 'super_admin', 'active' => true,
        ]);

        $this->actingAs($superAdmin);
        $this->assertSame([$admin->email], WorkspaceRead::table('users')->pluck('email')->all());
    }

    public function test_manager_can_read_explicitly_assigned_legacy_cashier(): void
    {
        config(['workspaces.enabled' => true]);
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'A']);
        $workspaceId = DB::table('workspaces')->insertGetId(['organization_id' => $organizationId, 'name' => 'A']);
        $manager = User::factory()->create(['role' => 'manager', 'workspace_id' => $workspaceId]);
        $cashier = User::factory()->create([
            'role' => 'cashier', 'workspace_id' => $workspaceId,
            'assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager',
        ]);
        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId, 'user_id' => $manager->id,
            'user_role' => 'manager', 'active' => true,
        ]);

        $this->actingAs($manager);
        $this->assertContains($cashier->email, WorkspaceRead::table('users')->pluck('email')->all());
    }
}
