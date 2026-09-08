<?php

use App\Http\Controllers\Api\GameServerController;
use App\Http\Controllers\DemoPlayController;
use App\Http\Controllers\GameAssetController;
use Illuminate\Support\Facades\Route;

/*
 * The site root (`/`) and the rest of the player-facing casino are defined in
 * routes/frontend.php (registered from bootstrap/app.php).
 *
 * Game front-end delivery.
 *   /games/{code}?token=…  → boot a play session, serve the game shell
 *   /games/{code}/{path}   → static asset from the uploaded bundle
 */
// "Play demo" from the admin panel — staff only (see DemoPlayController).
Route::get('games/demo/{code}', [DemoPlayController::class, 'start'])->name('games.demo');

Route::get('games/{code}', [GameAssetController::class, 'play'])->name('games.play');

/*
 * Real Pragmatic Play's modern "gs2c" HTML5 client hard-codes its game-command
 * endpoint at this exact path (baked into the bundle's own bootstrap config —
 * see html5Game.html's `gameConfig.gameService`), unlike the legacy `slotEvent`
 * bundles' `/game/{code}/server`. Must be registered before the asset wildcard
 * below (a GET-only route, so no ordering hazard, but kept adjacent for clarity).
 */
Route::post('games/{code}/gs2c/v3/gameService', [GameServerController::class, 'handle'])
    ->name('games.server.gs2c');

Route::get('games/{code}/{path}', [GameAssetController::class, 'asset'])
    ->where('path', '.*')
    ->name('games.asset');

/*
 * Legacy per-game command endpoint. Novomatic / Greentube (`slotEvent`) bundles
 * hard-code `POST /game/<Code>/server?sessionId=…` — auth is the game-session
 * token, CSRF-excluded in bootstrap/app.php.
 */
Route::post('game/{code}/server', [GameServerController::class, 'handle'])->name('games.server.legacy');

/*
 * Legacy EGT "GamePlatform" bundles fetch this from an absolute path to learn
 * where the game WebSocket lives (see `php artisan game:socket`).
 */
Route::get('socket_config.json', function () {
    $s = config('games.socket');

    return response()->json([
        'port' => $s['public_port'].$s['path'],
        'host' => $s['public_host'],
        'prefix' => 'http://',
        'host_ws' => $s['public_host'],
        'prefix_ws' => $s['scheme'].'://',
        'ssl' => $s['scheme'] === 'wss',
    ]);
})->name('games.socket-config');
