<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\CountOperationalRequests::class);
        // Trusted addresses are loaded at request time from config/trustedproxy.php.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->web(append: [\App\Http\Middleware\EnsureActiveAccount::class, \App\Http\Middleware\RequirePasswordSetup::class]);
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
        $middleware->validateCsrfTokens(except: ['api/payments/paymongo/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UniqueConstraintViolationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This record already exists or has already been processed. Refresh before retrying.'], 409);
            }
        });
    })->create();
