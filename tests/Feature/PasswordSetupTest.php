<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_blocks_business_access_and_requires_a_new_password(): void
    {
        $u = User::factory()->create(['role' => 'cashier']);
        $u->forceFill(['password' => Hash::make('Temporary-Password-123'), 'must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()])->save();
        $this->actingAs($u)->getJson('/api/kita-data')->assertForbidden();
        $this->postJson('/password/setup', ['current_password' => 'Temporary-Password-123', 'password' => 'Temporary-Password-123', 'password_confirmation' => 'Temporary-Password-123'])->assertUnprocessable();
        $this->postJson('/password/setup', ['current_password' => 'Temporary-Password-123', 'password' => 'My personal passphrase 123', 'password_confirmation' => 'My personal passphrase 123'])->assertOk();
        $u->refresh();
        $this->assertFalse((bool) $u->must_change_password);
        $this->assertTrue(Hash::check('My personal passphrase 123', $u->password));
        $this->assertGuest();
    }

    public function test_expired_temporary_password_cannot_complete_setup(): void
    {
        $u = User::factory()->create(['role' => 'cashier']);
        $u->forceFill(['password' => Hash::make('Temporary-Password-123'), 'must_change_password' => true, 'temporary_password_expires_at' => now()->subMinute()])->save();
        $this->actingAs($u)->postJson('/password/setup', ['current_password' => 'Temporary-Password-123', 'password' => 'My personal passphrase 123', 'password_confirmation' => 'My personal passphrase 123'])->assertUnprocessable();
        $this->assertTrue((bool) $u->fresh()->must_change_password);
    }

    public function test_reset_code_is_single_use_and_invalidates_old_sessions(): void
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        DB::table('password_reset_challenges')->insert(['email' => $u->email, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5), 'credential_version' => 0]);
        $data = ['email' => $u->email, 'otp' => '000000', 'password' => 'My personal passphrase 123', 'password_confirmation' => 'My personal passphrase 123'];
        $this->postJson('/password/verify', $data)->assertUnprocessable();
        $this->assertDatabaseHas('password_reset_challenges', ['email' => $u->email, 'attempts' => 1]);
        $data['otp'] = '123456';
        $this->postJson('/password/verify', $data)->assertOk();
        $this->postJson('/password/verify', $data)->assertUnprocessable();
        $this->postJson('/password/recovery', $data)->assertOk();
        $this->postJson('/password/recovery', $data)->assertUnprocessable();
        $this->actingAs($u->fresh())->withSession(['credential_version' => 0])->getJson('/api/kita-data')->assertUnauthorized();
    }

    public function test_new_account_has_generated_emailed_credentials_and_separate_names(): void
    {
        Mail::fake();
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->postJson('/api/accounts', ['first_name' => 'New', 'last_name' => 'Cashier', 'email' => 'new@example.com', 'role' => 'cashier'])->assertCreated()->assertJsonPath('account.must_change_password', true);
        $u = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('New Cashier', $u->name);
        $this->assertNotNull($u->temporary_password_expires_at);
        $this->assertSame(1, (int) $u->credential_version);
        $this->postJson('/api/accounts', ['first_name' => 'Other', 'last_name' => 'Cashier', 'email' => 'other@example.com', 'role' => 'cashier', 'password' => 'NameBasedPassword'])->assertUnprocessable();
    }
}
