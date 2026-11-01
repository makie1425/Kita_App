<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequirePasswordSetup
{
    public function handle(Request $r, Closure $next)
    {
        $u = $r->user();
        if ($u && (int) $r->session()->get('credential_version', 0) !== (int) $u->credential_version) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return $r->expectsJson() ? response()->json(['message' => 'Please sign in again.'], 401) : redirect('/');
        }
        if ($u && $u->must_change_password && ! $r->is('password/setup', 'logout', 'auth/csrf-token')) {
            return $r->expectsJson() ? response()->json(['message' => 'Set your password before continuing.', 'redirect' => '/password/setup'], 403) : redirect('/password/setup');
        }

        return $next($r);
    }
}
