<?php

use App\Http\Controllers\Frontend\AccountController;
use App\Http\Controllers\Frontend\AuthController;
use App\Http\Controllers\Frontend\GameController;
use App\Http\Controllers\Frontend\LobbyController;
use Illuminate\Support\Facades\Route;

/*
 * The first-party player casino. Lives at the site root; `/admin` (Filament) is
 * staff-only and unaffected. Auth is the standard `web` session guard; the
 * `player` middleware additionally rejects staff and blocked accounts.
 *
 * `code` is a game_templates.code (letters/digits/_/- , keeps the provider
 * suffix, e.g. ActionMoneyEGT).
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('frontend.login');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('frontend.login.attempt');
});

Route::post('logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('frontend.logout');

Route::middleware('player')->group(function () {
    Route::get('/', [LobbyController::class, 'index'])->name('frontend.lobby');

    Route::get('play/{code}', [GameController::class, 'show'])
        ->whereAlphaNumeric('code')
        ->name('frontend.play');
    Route::get('launch/{code}', [GameController::class, 'launch'])
        ->whereAlphaNumeric('code')
        ->name('frontend.launch');

    Route::get('account', [AccountController::class, 'index'])->name('frontend.account');
    Route::get('me/balance', [AccountController::class, 'balance'])->name('frontend.balance');
});
