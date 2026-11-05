<?php

namespace Tests\Feature;

use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\UsesWorkspaceFixtures;

    public function test_inactive_credentials_have_exact_message_without_disclosing_status_for_wrong_password(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'Inactive', 'password' => 'secret123']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Email or password is incorrect.');
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', User::DEACTIVATED_MESSAGE);
        $this->assertGuest();
        $this->assertDatabaseCount('login_otps', 0);
    }

    public function test_deactivation_between_password_and_otp_blocks_login(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'secret123']);
        $otp = $this->postJson('/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk()->json('test_otp');
        $user->update(['status' => 'Inactive']);
        $this->postJson('/otp/verify', ['email' => $user->email, 'otp' => $otp])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', User::DEACTIVATED_MESSAGE);
        $this->assertGuest();
        $this->assertNull(LoginOtp::first()->used_at);
    }

    public function test_otp_request_requires_password_authenticated_pending_session(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->postJson('/otp/request', ['email' => $user->email])->assertForbidden();
        $this->assertDatabaseCount('login_otps', 0);
    }

    public function test_super_admin_can_change_status_but_cannot_delete_accounts_or_deactivate_self(): void
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        $user = User::factory()->create(['role' => 'admin', 'password' => 'secret123']);
        $this->assignWorkspace(User::factory()->create(['role'=>'manager']), $super, $user);
        $url = '/api/accounts/admin/'.$user->id;
        $this->actingAs($super)->patchJson($url, ['status' => 'Inactive'])->assertOk()->assertJsonPath('account.status', 'Inactive');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'Inactive']);
        $this->deleteJson($url)->assertStatus(405);
        $this->deleteJson('/api/accounts/superadmin/'.$super->id)->assertStatus(405);
        $this->patchJson('/api/accounts/superadmin/'.$super->id, ['status' => 'Inactive'])->assertForbidden();
        $this->patchJson($url, ['status' => 'invalid'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'Active'])->assertOk()->assertJsonPath('account.status', 'Active');
        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk();
        $this->assertGuest(); // Password authentication still requires the email OTP.
    }

    public function test_deactivate_then_reactivate_does_not_restore_old_sessions(): void
    {
        $super=User::factory()->create(['role'=>'superadmin']);
        $user=User::factory()->create(['role'=>'admin']);
        $this->assignWorkspace(User::factory()->create(['role'=>'manager']),$super,$user);
        $version=(int)$user->credential_version;
        $this->actingAs($super)->patchJson('/api/accounts/admin/'.$user->id,['status'=>'Inactive'])->assertOk();
        $this->assertSame($version+1,(int)$user->fresh()->credential_version);
        $this->patchJson('/api/accounts/admin/'.$user->id,['status'=>'Active'])->assertOk();
        $this->actingAs($user->fresh())->withSession(['credential_version'=>$version])->getJson('/auth/session')->assertUnauthorized()->assertJsonPath('session_revoked',true);
        $this->assertGuest();
        $this->actingAs($user->fresh())->withSession(['credential_version'=>$version+1])->getJson('/auth/session')->assertOk()->assertJsonPath('active',true)->assertHeader('Cache-Control','no-store, private');
    }

    public function test_session_probe_rejects_guests_and_inactive_accounts(): void
    {
        $this->getJson('/auth/session')->assertUnauthorized();
        $user=User::factory()->create(['status'=>'Inactive']);
        $this->actingAs($user)->getJson('/auth/session')->assertUnauthorized()->assertJsonPath('deactivated',true);
        $this->assertGuest();
    }

    public function test_revoked_session_is_logged_out_for_api_and_page_requests(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);
        $user->update(['status' => 'Inactive']);
        $this->getJson('/api/kita-data')->assertUnauthorized()->assertJsonPath('message', User::DEACTIVATED_MESSAGE)->assertJsonPath('deactivated', true);
        $this->assertGuest();
        $this->actingAs($user)->get('/')->assertRedirect('/')->assertSessionHas('login_error', User::DEACTIVATED_MESSAGE);
        $this->assertGuest();
    }

    public function test_dashboard_exposes_operations_only_and_requires_privileged_role(): void
    {
        $this->getJson('/api/super-admin/dashboard')->assertUnauthorized();
        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/super-admin/dashboard')->assertForbidden();
        }
        $response = $this->actingAs(User::factory()->create(['role' => 'superadmin']))->getJson('/api/super-admin/dashboard')->assertOk();
        foreach (['total_users','average_daily_events','activity_days','recent_activity','database_response_ms','generation_ms'] as $removed) {
            $this->assertArrayNotHasKey($removed, $response->json());
        }
        $response->assertJsonPath('availability', null)->assertJsonCount(0, 'errors');
    }

    public function test_accounts_show_retained_otp_login_time_without_exposing_otp_secrets(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'secret123']);
        $super = User::factory()->create(['role' => 'superadmin']);
        $this->assignWorkspace(User::factory()->create(['role'=>'manager']), $super, $user);
        $otp = $this->postJson('/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk()->json('test_otp');
        $this->postJson('/otp/verify', ['email' => $user->email, 'otp' => $otp])->assertOk();
        $response = $this->actingAs($super)->getJson('/api/accounts')->assertOk();
        $this->assertNotNull($response->json('accounts.0.lastOtpLogin'));
        $this->assertArrayNotHasKey('password', $response->json('accounts.0'));
        $this->assertArrayNotHasKey('otp_hash', $response->json('accounts.0'));
    }

}
