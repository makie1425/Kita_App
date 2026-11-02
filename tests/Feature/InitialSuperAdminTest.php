<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_creates_only_the_super_admin_and_an_explicit_workspace(): void
    {
        config(['initial_super_admin.email' => 'owner@example.com', 'initial_super_admin.password' => 'a-private-password-123']);

        $this->artisan('accounts:seed-initial-superadmin')->assertExitCode(0);

        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('super_admin', $user->normalizedRole());
        $this->assertTrue(Hash::check('a-private-password-123', $user->password));
        $this->assertSame(1, User::count());
        $this->assertNotNull($user->workspace_id);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $user->workspace_id, 'user_id' => $user->id,
            'user_role' => 'super_admin', 'can_manage_accounts' => true, 'active' => true,
        ]);

        $this->artisan('accounts:seed-initial-superadmin')->assertExitCode(0);
        $this->assertSame(1, User::count());
        $this->assertSame(1, DB::table('workspaces')->count());
    }

    public function test_existing_records_are_not_assigned_to_a_new_super_admin(): void
    {
        User::factory()->create(['role' => 'cashier']);
        config(['initial_super_admin.email' => 'owner@example.com', 'initial_super_admin.password' => 'a-private-password-123']);

        $this->artisan('accounts:seed-initial-superadmin')->assertExitCode(0);

        $this->assertNull(User::where('email', 'owner@example.com')->firstOrFail()->workspace_id);
        $this->assertSame(0, DB::table('workspaces')->count());
    }
}
