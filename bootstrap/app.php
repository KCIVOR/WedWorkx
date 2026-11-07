<?php

use App\Http\Middleware\AuthenticateHermes;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Read .env through $_ENV/$_SERVER only. putenv()/getenv() are shared by every thread of a threaded server
// (Apache on Windows), and concurrent requests then sometimes saw no settings at all and failed with a 500.
\Illuminate\Support\Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies for Cloudflare tunnel HTTPS header forwarding
        $middleware->trustProxies(at: '*');

        // HitPay posts server to server, with no session. It is authenticated by its signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/hitpay']);

        $middleware->alias(['hermes.token' => AuthenticateHermes::class, 'dev.mode' => \App\Http\Middleware\EnsureDevMode::class, 'admin' => \App\Http\Middleware\EnsureAdmin::class]);

        // The admin check must run before route-model binding, or a guest could tell real supplier ids (404) from unused ones.
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: \App\Http\Middleware\EnsureAdmin::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API always answers in JSON, even for 404s, validation errors and rate limits.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
