<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceOwnershipAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_unassigned_and_conflicting_ownership_without_writing(): void
    {
        $organization = DB::table('organizations')->insertGetId(['name' => 'Audit organization']);
        $workspace = DB::table('workspaces')->insertGetId(['organization_id' => $organization, 'name' => 'Audit workspace']);
        $first = User::factory()->create(['role' => 'manager']);
        $second = User::factory()->create(['role' => 'manager']);
        $first->forceFill(['workspace_id' => $workspace])->save();
        $second->forceFill(['workspace_id' => $workspace])->save();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $cashier->forceFill([
            'workspace_id' => $workspace,
            'created_by_user_id' => $first->id,
            'created_by_user_role' => 'manager',
            'assigned_manager_id' => $second->id,
            'assigned_manager_role' => 'manager',
        ])->save();
        DB::table('audit_logs')->insert(['action' => 'Audit test', 'record' => 'Test']);

        $before = DB::table('users')->orderBy('email')->get()->toJson();
        $this->assertSame(0, Artisan::call('workspaces:audit-ownership', ['--sample' => 1]));
        $output = Artisan::output();
        $this->assertStringContainsString('audit_logs: 1', $output);
        $this->assertStringContainsString('Managers sharing workspace: 2', $output);
        $this->assertStringContainsString('Cashiers with conflicting creator/manager: 1', $output);
        $this->assertStringContainsString('Accounts without matching workspace membership: 3', $output);
        $this->assertSame($before, DB::table('users')->orderBy('email')->get()->toJson());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Audit test', 'workspace_id' => null]);
    }

    public function test_invalid_sample_is_rejected_before_querying(): void
    {
        $this->assertSame(1, Artisan::call('workspaces:audit-ownership', ['--sample' => 51]));
        $this->assertStringContainsString('0 to 50', Artisan::output());
    }
}
