<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the player-facing frontend. The visitor must be authenticated, not
 * blocked, and an actual player — {@see User::isPlayer()} means the
 * `user` role and no staff role. Staff use the `/admin` panel, never this site.
 */
class EnsurePlayer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('frontend.login'));
        }

        abort_if($user->is_blocked, 403, 'This account is blocked.');
        abort_unless($user->isPlayer(), 403, 'Staff accounts sign in through the control panel.');

        return $next($request);
    }
}
