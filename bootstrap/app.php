<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Vercel এর প্রক্সির পেছনে https ঠিকমতো চেনার জন্য।
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
        // Every /api request is treated as JSON (errors are JSON, never redirects).
        $middleware->api(prepend: [\App\Http\Middleware\ForceJsonResponse::class]);
        $middleware->validateCsrfTokens(except: [
            'payment/sslcommerz/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();