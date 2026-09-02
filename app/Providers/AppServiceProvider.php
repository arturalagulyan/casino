<?php

namespace App\Providers;

use App\Models\User;
use App\Services\GamePlay\GameRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One registry so title/provider overrides registered at boot stick.
        $this->app->singleton(GameRegistry::class);
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
