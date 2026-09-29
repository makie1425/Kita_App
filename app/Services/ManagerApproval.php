<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ManagerApproval
{
    public static function verify(Request $request, int $managerId, string $pin, string $field = 'manager_pin'): User
    {
        abort_unless($request->user(), 401, 'Sign in to request manager approval.');
        $key = 'manager-approval:'.$managerId;
        $requesterKey = 'manager-approval-requester:'.$request->user()->role.':'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($requesterKey, 5), 429,
            'Too many incorrect PIN attempts. Try again in 5 minutes.');

        // Legacy accounts have role-scoped numeric IDs. Never use User::find here.
        $manager = User::where('id', $managerId)->whereRaw('LOWER(role) = ?', ['manager'])->first();
        if (! $manager || strtolower($manager->status ?? 'Active') !== 'active' || ! $manager->verifyApprovalPin($pin)) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit($requesterKey, 300);
            throw ValidationException::withMessages([$field => 'The selected active manager or approval PIN is incorrect.']);
        }

        return $manager;
    }
}
