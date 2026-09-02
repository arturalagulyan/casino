<?php

use App\Http\Controllers\Api\GameLaunchController;
use App\Http\Controllers\Api\GameServerController;
use Illuminate\Support\Facades\Route;

/*
 * Seamless-wallet integration for game providers.
 * ← legacy routes/api.php (PlayersController) — rebuilt clean:
 *   - key comes from the `X-Api-Key` header (or legacy `api` header)
 *   - IP allow-list enforced from api_keys.allowed_ips
 *   - launch token is a signed, 1-hour Crypt payload (no hard-coded key/iv)
 *
 * Actual game serving (runGame / runServer) is the spin-engine phase.
 */
Route::middleware('api.key')->prefix('game')->group(function () {
    Route::post('launch', [GameLaunchController::class, 'launch'])->name('api.game.launch');
});

Route::get('game/play', [GameLaunchController::class, 'play'])->name('api.game.play');

// Game-play commands from the front-end bundle — auth is the game-session token.
Route::post('game/{code}/server', [GameServerController::class, 'handle'])->name('api.game.server');
