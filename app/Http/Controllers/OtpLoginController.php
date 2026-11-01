<?php

namespace App\Http\Controllers;

use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class OtpLoginController extends Controller
{
    private function requiresOtp(User $user): bool
    {
        return in_array($user->normalizedRole(), ['manager', 'admin', 'super_admin'], true);
    }

    private function dashboardFor(User $user): string
    {
        return match ($user->normalizedRole()) {
            'manager' => 'mgrDashboard',
            'admin' => 'admDashboard',
            'super_admin' => 'saDashboard',
            default => 'pos',
        };
    }

    private function loginResponse(User $user): array
    {
        return [
            'message' => 'Signed in successfully.',
            'password_setup_required' => (bool)$user->must_change_password,
            'role' => $user->normalizedRole() === 'super_admin' ? 'superadmin' : $user->normalizedRole(),
            'dashboard' => $this->dashboardFor($user),
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'csrf_token' => csrf_token(),
        ];
    }

    private function issueOtpForUser(User $user): string
    {
        $otp = (string) random_int(100000, 999999);

        $record = LoginOtp::query()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'last_sent_at' => now(),
        ]);

        session([
            'pending_login_user_id' => $user->id,
            'pending_login_email' => $user->email,
        ]);

        try {
            Mail::raw('Your KITA login OTP is '.$otp.'. It expires in 5 minutes.', function ($message) use ($user): void {
                $message->to($user->email)->subject('Your KITA login OTP');
            });
        } catch (TransportExceptionInterface $exception) {
            $record->delete();
            session()->forget(['pending_login_user_id', 'pending_login_email']);
            \Illuminate\Support\Facades\Log::error('Login OTP delivery failed. Check mail provider connectivity and configuration.');
            abort(503, 'Unable to send your sign-in code. Please contact your administrator to check email delivery, then try again.');
        }

        return $otp;
    }

    public function login(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], ['email.email' => 'Enter a valid email address.']);

        $key = 'login:'.$request->ip().':'.$validated['email'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again in a minute.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$validated['email']])->first();
        $storedPassword = $user?->password;
        $validPassword = false;

        if (is_string($storedPassword) && $storedPassword !== '') {
            $validPassword = password_verify($validated['password'], $storedPassword)
                || $storedPassword === $validated['password'];
        }

        if (! $validPassword || ! in_array($user->normalizedRole(), ['cashier', 'manager', 'admin', 'super_admin'], true)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }

        if (! $user->isActive()) {
            session()->forget(['pending_login_user_id', 'pending_login_email']);
            throw ValidationException::withMessages(['email' => User::DEACTIVATED_MESSAGE]);
        }

        if ($storedPassword !== $validated['password'] && Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($validated['password'])])->save();
        }

        if ($storedPassword === $validated['password'] && ! password_verify($validated['password'], $user->password)) {
            $user->forceFill(['password' => Hash::make($validated['password'])])->save();
        }

        if ($user->must_change_password && (!$user->temporary_password_expires_at || now()->gte($user->temporary_password_expires_at))) throw ValidationException::withMessages(['password'=>'Temporary password expired. Use Resend Setup Credentials.']);

        if ($this->requiresOtp($user)) {
            $otp = $this->issueOtpForUser($user);

            $response = [
                'message' => 'OTP required to complete sign-in.',
                'otp_required' => true,
                'email' => $user->email,
            ];

            if (app()->environment('testing')) {
                $response['test_otp'] = $otp;
            }

            return response()->json($response);
        }

        RateLimiter::clear($key);
        Auth::login($user);
        session(['credential_version'=>(int)$user->credential_version]);
        $request->session()->regenerate();

        return response()->json($this->loginResponse($user));
    }

    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], ['email.email' => 'Enter a valid email address.']);

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [Str::lower(trim($validated['email']))])->first();

        if (! $user || ! $user->isActive() || ! $this->requiresOtp($user)) {
            abort(404);
        }

        abort_unless((int) session('pending_login_user_id') === (int) $user->id && session('pending_login_email') === $user->email, 403);
        $this->issueOtpForUser($user);

        return response()->json([
            'message' => 'OTP sent.',
            'otp_required' => true,
            'email' => $user->email,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
        ], ['email.email' => 'Enter a valid email address.']);

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [Str::lower(trim($validated['email']))])->first();
        $pendingId = (int) session('pending_login_user_id', 0);

        if (! $user || ! $this->requiresOtp($user) || (int) $user->id !== $pendingId || session('pending_login_email') !== $user->email) {
            abort(404);
        }

        $otpRecord = LoginOtp::query()->where('user_id', $user->id)->where('email', $user->email)->latest('id')->first();

        if (! $otpRecord || $otpRecord->used_at || $otpRecord->attempts >= 5 || $otpRecord->expires_at->isPast() || ! password_verify($validated['otp'], $otpRecord->otp_hash)) {
            if ($otpRecord && ! $otpRecord->used_at && $otpRecord->attempts < 5) {
                $otpRecord->increment('attempts');
            }
            throw ValidationException::withMessages(['otp' => 'Invalid or expired OTP.']);
        }

        if (! $user->isActive()) {
            session()->forget(['pending_login_user_id', 'pending_login_email']);
            throw ValidationException::withMessages(['email' => User::DEACTIVATED_MESSAGE]);
        }

        if ($user->must_change_password && (!$user->temporary_password_expires_at || now()->gte($user->temporary_password_expires_at))) throw ValidationException::withMessages(['password'=>'Temporary password expired. Request new setup credentials.']);
        $otpRecord->forceFill(['used_at' => now()])->save();

        Auth::login($user);
        session(['credential_version'=>(int)$user->credential_version]);
        $request->session()->regenerate();
        session()->forget(['pending_login_user_id', 'pending_login_email']);

        return response()->json($this->loginResponse($user));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.', 'csrf_token' => csrf_token()]);
    }
}
