<?php

use App\Http\Controllers\Api\GameServerController;
use App\Http\Controllers\DemoPlayController;
use App\Http\Controllers\GameAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Game front-end delivery.
 *   /games/{code}?token=…  → boot a play session, serve the game shell
 *   /games/{code}/{path}   → static asset from the uploaded bundle
 */
// "Play demo" from the admin panel — staff only (see DemoPlayController).
Route::get('games/demo/{code}', [DemoPlayController::class, 'start'])->name('games.demo');

Route::get('games/{code}', [GameAssetController::class, 'play'])->name('games.play');
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
