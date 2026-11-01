<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountCredentials
{
    public static function issue(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::where('email', $user->email)->lockForUpdate()->firstOrFail();
            abort_unless($user->isActive() && $user->must_change_password, 422, 'This account does not need setup credentials.');
            $password = Str::random(24);
            $user->forceFill(['password' => Hash::make($password), 'temporary_password_expires_at' => now()->addHours(24), 'credential_version' => (int) $user->credential_version + 1, 'remember_token' => Str::random(60)])->save();
            DB::table('password_reset_challenges')->where('email', $user->email)->delete();
            DB::table('login_otps')->where('email', $user->email)->whereNull('used_at')->update(['used_at' => now()]);
            Mail::raw('Your KITA account is ready. Username: '.($user->username ?: $user->email)."\nSign-in email: ".$user->email."\nTemporary password: ".$password."\nSign in at ".url('/').'. Change your password on first login. These credentials expire in 24 hours.', fn ($m) => $m->to($user->email)->subject('KITA account setup'));
        });
    }
}
