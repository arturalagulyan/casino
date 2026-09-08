<?php

use App\Http\Middleware\EnsurePlayer;
use App\Http\Middleware\ResolveApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // First-party player casino — served at the site root, `web` guard.
            Route::middleware('web')->group(__DIR__.'/../routes/frontend.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key' => ResolveApiKey::class,
            'player' => EnsurePlayer::class,
        ]);

        // Legacy game bundles POST here cross-site from an <iframe> with a
        // game-session token — no CSRF cookie.
        $middleware->validateCsrfTokens(except: [
            'game/*/server',
            'games/*/gs2c/v3/gameService',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
