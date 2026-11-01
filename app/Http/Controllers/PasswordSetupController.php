<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordSetupController extends Controller
{
    public function page(Request $r)
    {
        return view('password-setup', ['setup' => $r->is('password/setup')]);
    }

    public function resend(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($d['email']));
        $key = 'setup-resend:'.hash('sha256', $email);
        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 60);
            $u = User::where('email', $email)->first();
            if ($u && $u->isActive() && $u->must_change_password) {
                AccountCredentials::issue($u);
            }
        }

        return response()->json(['message' => 'If this account needs setup, new credentials have been emailed.']);
    }

    public function requestCode(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($d['email']));
        $key = 'reset-code:'.hash('sha256', $email);
        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 60);
            $u = User::where('email', $email)->first();
            if ($u && $u->isActive()) {
                DB::transaction(function () use ($u) {
                    $u = User::where('email', $u->email)->lockForUpdate()->firstOrFail();
                    $code = (string) random_int(100000, 999999);
                    DB::table('password_reset_challenges')->updateOrInsert(['email' => $u->email], ['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5), 'attempts' => 0, 'credential_version' => $u->credential_version]);
                    Mail::raw('Your KITA password reset code is '.$code.'. It expires in 5 minutes. If you did not request this, ignore this email.', fn ($m) => $m->to($u->email)->subject('KITA password reset code'));
                });
            }
        }

        return response()->json(['message' => 'If the account is active, a reset code has been emailed.']);
    }

    public function verifyCode(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'otp' => 'required|digits:6']);
        $email = strtolower(trim($d['email']));
        $version = DB::transaction(function () use ($email, $d) {
            $u = User::where('email', $email)->lockForUpdate()->first();
            $c = DB::table('password_reset_challenges')->where('email', $email)->lockForUpdate()->first();
            if (! $u || ! $u->isActive() || ! $c || $c->attempts >= 5 || now()->gte($c->expires_at) || (int) $c->credential_version !== (int) $u->credential_version) {
                return null;
            }
            DB::table('password_reset_challenges')->where('email', $email)->increment('attempts');
            if (! Hash::check($d['otp'], $c->code_hash)) {
                return null;
            }
            DB::table('password_reset_challenges')->where('email', $email)->delete();

            return (int) $u->credential_version;
        });
        if ($version === null) {
            throw ValidationException::withMessages(['otp' => 'Invalid or expired code.']);
        }
        $r->session()->regenerate();
        $r->session()->put('password_reset_grant', ['email' => $email, 'version' => $version, 'expires' => now()->addMinutes(5)->timestamp]);

        return response()->json(['message' => 'Email verified. Enter your new password.', 'verified' => true]);
    }

    public function save(Request $r)
    {
        $setup = $r->is('password/setup');
        $d = $r->validate(['password' => 'required|string|min:12|max:255|confirmed', ...($setup ? ['current_password' => 'required|string'] : ['email' => 'required|email'])]);
        $error = DB::transaction(function () use ($r, $d, $setup) {
            $u = User::where('email', $setup ? $r->user()->email : strtolower(trim($d['email'])))->lockForUpdate()->first();
            if (! $u || ! $u->isActive()) {
                return 'Invalid or expired credentials.';
            }
            if ($setup) {
                if (! $u->must_change_password || ! $u->temporary_password_expires_at || now()->gte($u->temporary_password_expires_at) || ! Hash::check($d['current_password'], $u->password)) {
                    return 'Temporary password is invalid or expired. Request new setup credentials.';
                }
            } else {
                $grant = $r->session()->get('password_reset_grant');
                if (! $grant || $grant['email'] !== $u->email || $grant['version'] !== (int) $u->credential_version || $grant['expires'] <= now()->timestamp) {
                    return 'Verify a new email code before resetting your password.';
                }
            }

            if (Hash::check($d['password'], $u->password)) {
                return 'Choose a different password.';
            }
            $u->forceFill(['password' => Hash::make($d['password']), 'must_change_password' => false, 'temporary_password_expires_at' => null, 'credential_version' => (int) $u->credential_version + 1, 'remember_token' => Str::random(60)])->save();
            DB::table('password_reset_challenges')->where('email', $u->email)->delete();
            DB::table('login_otps')->where('email', $u->email)->whereNull('used_at')->update(['used_at' => now()]);
            DB::table('audit_logs')->insert(['ts' => now(), 'user' => $u->name, 'action' => $setup ? 'Completed password setup' : 'Reset password', 'record' => 'Account '.$u->role.':'.$u->id, 'workspace_id' => $u->workspace_id]);
            DB::afterCommit(function () use ($u) {
                try {
                    Mail::raw('Your KITA password was changed. If you did not make this change, contact your authorized administrator immediately.', fn ($m) => $m->to($u->email)->subject('KITA password changed'));
                } catch (\Throwable $e) {
                    Log::warning('Password change notification could not be delivered.');
                }
            });

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['password' => $error]);
        }
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->json(['message' => 'Password saved. Sign in with your new password.', 'redirect' => '/']);
    }
}
