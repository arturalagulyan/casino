<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the provider's ApiKey from the request, enforces the IP allow-list,
 * and stashes it on the request as `api_key`. ← legacy `ipcheck` +
 * `PlayersController` reading the `api` header.
 */
class ResolveApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key')
            ?? $request->header('api')
            ?? $request->input('api_key');

        $apiKey = $key ? ApiKey::where('key', $key)->where('is_active', true)->first() : null;

        if (! $apiKey) {
            return response()->json(['error' => 'Invalid or inactive API key.'], 401);
        }

        $allowed = $apiKey->allowed_ips ?? [];

        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            return response()->json(['error' => 'IP not allowed for this key.'], 403);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
