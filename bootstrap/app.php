<?php

use App\Http\Middleware\ResolveApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key' => ResolveApiKey::class,
        ]);

        // Legacy game bundles POST here cross-site from an <iframe> with a
        // game-session token — no CSRF cookie.
        $middleware->validateCsrfTokens(except: [
            'game/*/server',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
