<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->isActive()) {
            return (new EnsureActiveAccount)->handle($request, $next);
        }

        $allowedRoles = array_map(fn (string $role): string => $this->normalizedRole($role), $roles);

        if (in_array($this->normalizedRole($user->role), $allowedRoles, true)) {
            return $next($request);
        }

        abort(403);
    }

    private function normalizedRole(?string $role): string
    {
        $role = Str::of((string) $role)->lower()->replace('-', '_')->replace(' ', '_')->toString();

        return $role === 'superadmin' ? 'super_admin' : $role;
    }
}
