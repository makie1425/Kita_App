<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountAuditAndManagerSelectorTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $name): int
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => $name]);
        return DB::table('workspaces')->insertGetId(['organization_id' => $organizationId, 'name' => $name]);
    }

    private function member(User $user, int $workspaceId, bool $manage = false): void
    {
        $user->forceFill(['workspace_id' => $workspaceId])->save();
        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId, 'user_id' => $user->id,
            'user_role' => $user->normalizedRole(), 'active' => true,
            'can_manage_accounts' => $manage,
        ]);
    }

    public function test_account_list_queries_only_authorized_otp_emails_and_audit_uses_target_workspace(): void
    {
        Mail::fake();
        $workspaceId = $this->workspace('Owned');
        $foreignWorkspaceId = $this->workspace('Foreign');
        $manager = User::factory()->create(['role' => 'manager']);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $foreign = User::factory()->create(['role' => 'cashier']);
        $this->member($manager, $workspaceId, true);
        $this->member($cashier, $workspaceId);
        $this->member($foreign, $foreignWorkspaceId);
        $cashier->forceFill(['assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager'])->save();
        foreach ([$cashier, $foreign] as $account) {
            DB::table('login_otps')->insert([
                'user_id' => $account->id, 'email' => $account->email,
                'otp_hash' => 'test', 'expires_at' => now()->addMinutes(5), 'used_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'login_otps')) {
                $queries[] = $event;
            }
        });
        $this->actingAs($manager)->getJson('/api/accounts')->assertOk()
            ->assertJsonCount(1, 'accounts')->assertJsonPath('accounts.0.email', $cashier->email);
        $this->assertCount(1, $queries);
        $this->assertContains($cashier->email, $queries[0]->bindings);
        $this->assertNotContains($foreign->email, $queries[0]->bindings);

        $this->patchJson('/api/accounts/cashier/'.$cashier->id, [
            'status' => 'Inactive', 'workspace_id' => $foreignWorkspaceId,
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Updated account', 'workspace_id' => $workspaceId,
        ]);
    }

    public function test_foreign_legacy_name_does_not_block_account_update_but_owned_one_does(): void
    {
        Mail::fake();
        $workspaceId = $this->workspace('Owned');
        $foreignWorkspaceId = $this->workspace('Foreign');
        $manager = User::factory()->create(['role' => 'manager']);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->member($manager, $workspaceId, true);
        $this->member($cashier, $workspaceId);
        $cashier->forceFill(['assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager'])->save();
        DB::table('transactions')->insert([
            'uuid' => 'FOREIGN-PENDING', 'status' => 'Pending Payment',
            'cashier' => $cashier->name, 'workspace_id' => $foreignWorkspaceId,
        ]);
        $this->actingAs($manager)->patchJson('/api/accounts/cashier/'.$cashier->id, [
            'status' => 'Inactive',
        ])->assertOk();
        $cashier->forceFill(['status' => 'Active'])->save();
        DB::table('transactions')->insert([
            'uuid' => 'OWN-PENDING', 'status' => 'Pending Payment',
            'cashier' => $cashier->name, 'workspace_id' => $workspaceId,
        ]);
        $this->patchJson('/api/accounts/cashier/'.$cashier->id, [
            'status' => 'Inactive',
        ])->assertUnprocessable();
    }

    public function test_cashier_receives_only_assigned_manager_pin_option(): void
    {
        $workspaceId = $this->workspace('Owned');
        $otherWorkspaceId = $this->workspace('Other');
        $manager = User::factory()->create(['role' => 'manager']);
        $otherManager = User::factory()->create(['role' => 'manager']);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->member($manager, $workspaceId, true);
        $this->member($otherManager, $otherWorkspaceId, true);
        $this->member($cashier, $workspaceId);
        $cashier->forceFill(['assigned_manager_id' => $manager->id, 'assigned_manager_role' => 'manager'])->save();

        $response = $this->actingAs($cashier)->getJson('/api/kita-data')->assertOk()
            ->assertJsonCount(1, 'USERS.manager')->assertJsonPath('USERS.manager.0.id', $manager->id)
            ->assertJsonPath('USERS.manager.0.name', $manager->name)
            ->assertJsonCount(1, 'USERS.cashier');
        $this->assertSame(['id', 'name', 'status'], array_keys($response->json('USERS.manager.0')));
        $this->assertStringNotContainsString($otherManager->email, $response->getContent());
    }
}
