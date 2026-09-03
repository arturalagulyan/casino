<?php

namespace App\Providers;

use App\Models\User;
use App\Services\GamePlay\GameRegistry;
use App\Services\Legacy\LegacyGameReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One registry so title/provider overrides registered at boot stick.
        $this->app->singleton(GameRegistry::class);

        // Reads a local mirror of the legacy game files (import:legacy). The
        // w_game_path folder map comes from the legacy DB when it's reachable.
        $this->app->singleton(LegacyGameReader::class, function () {
            $paths = [];
            try {
                $paths = DB::connection('legacy')->table('game_path')->pluck('path', 'game')->all();
            } catch (\Throwable) {
                // legacy DB not configured — folders default to the game code
            }

            return new LegacyGameReader((string) config('legacy.app_games_path'), $paths);
        });
    }

    public function boot(): void
    {
        // Admins bypass every check; otherwise any ability that matches a
        // permission slug (e.g. "shops.manage") resolves against the user's
        // effective permissions. Returning null lets real policies still run.
        Gate::before(function (User $user, string $ability) {
            if ($user->isAdmin()) {
                return true;
            }

            return $user->hasPermission($ability) ? true : null;
        });
    }
}
