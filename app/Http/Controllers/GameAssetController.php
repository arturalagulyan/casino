<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\User;
use App\Services\GamePlay\GameConfig;
use App\Services\SeamlessWallet\GameLaunch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the uploaded front-end bundle for a game and boots a play session.
 *
 *   GET /games/{code}?token=<launch token>   → verify, open a game session,
 *                                              serve the entry HTML with a
 *                                              <script> telling the frontend
 *                                              where to POST commands.
 *   GET /games/{code}/{path}                  → stream a bundle asset (static).
 */
class GameAssetController extends Controller
{
    public function __construct(private GameLaunch $launcher) {}

    public function play(Request $request, string $code): Response
    {
        $template = GameTemplate::where('code', $code)->firstOr(fn () => abort(404));
        $bundle = $template->activeBundle;

        if (! $bundle) {
            // No uploaded files yet — fall back to the built-in demo shell so the
            // pipeline still works for engine=internal games.
            return $this->demoShell($request, $template);
        }

        ['user' => $user, 'game' => $game] = $this->resolveFromToken($request, $template);

        $session = GameSession::updateOrCreate(
            ['user_id' => $user->id, 'game_id' => $game->id],
            ['token' => bin2hex(random_bytes(20)), 'is_active' => true, 'last_seen_at' => now()],
        );

        $rel = $bundle->filePath($bundle->entry) ?? abort(500, 'Bundle entry file missing.');
        $html = $bundle->disk()->get($rel);

        // WebSocket bundles (EGT GamePlatform, …) identify the player by a
        // `sessionId` in the page, not a POST endpoint. Force this launch's token
        // in (overwriting any stale value the tab's sessionStorage kept).
        if ((new GameConfig($template, $game))->clientProtocol()->usesWebSocket()) {
            $token = json_encode($session->token);
            $inject = "<script>try{sessionStorage.setItem('sessionId',{$token});}catch(e){}</script>";
            $html = preg_match('/<head[^>]*>/i', $html)
                ? preg_replace('/(<head[^>]*>)/i', '$1'.$inject, $html, 1)
                : $inject.$html;

            return response($html)->header('Content-Type', 'text/html');
        }

        return response($this->injectBootstrap($html, $code, $session, $user, $game))
            ->header('Content-Type', 'text/html');
    }

    public function asset(string $code, string $path): Response
    {
        $template = GameTemplate::where('code', $code)->firstOr(fn () => abort(404));
        $bundle = $template->activeBundle ?? abort(404);

        $rel = $bundle->filePath($path);

        if (! $rel) {
            abort(404);
        }

        $disk = $bundle->disk();

        return response($disk->get($rel), 200, [
            'Content-Type' => $disk->mimeType($rel) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    // ---- helpers ----------------------------------------------------

    /** @return array{user: User, game: Game} */
    private function resolveFromToken(Request $request, GameTemplate $template): array
    {
        $token = $request->query('token') ?: abort(403, 'Missing launch token.');

        try {
            $resolved = $this->launcher->verifyToken($token);
        } catch (\Throwable $e) {
            abort(403, $e->getMessage());
        }

        if ($resolved['game']->template_id !== $template->id) {
            abort(403, 'Token is for a different game.');
        }

        return $resolved;
    }

    private function injectBootstrap(string $html, string $code, GameSession $session, User $user, Game $game): string
    {
        $config = json_encode([
            'endpoint' => url("/api/game/{$code}/server"),
            'session' => $session->token,
            'currency' => ($user->currency ?? $game->shop->currency)->value,
            'balance' => (float) $user->wallet->balance,
        ], JSON_UNESCAPED_SLASHES);

        $head = "<script>window.CasinoGame={$config};</script>";

        // Relative asset paths in the bundle resolve against the asset route,
        // not the launch URL — unless the bundle ships its own <base>.
        if (! preg_match('/<base\s/i', $html)) {
            $base = rtrim(url("/games/{$code}"), '/').'/';
            $head = "<base href=\"{$base}\">".$head;
        }

        return preg_match('/<head[^>]*>/i', $html)
            ? preg_replace('/(<head[^>]*>)/i', '$1'.addcslashes($head, '\\$'), $html, 1)
            : $head.$html;
    }

    private function demoShell(Request $request, GameTemplate $template): Response
    {
        $resolved = $this->resolveFromToken($request, $template);

        $session = GameSession::updateOrCreate(
            ['user_id' => $resolved['user']->id, 'game_id' => $resolved['game']->id],
            ['token' => bin2hex(random_bytes(20)), 'is_active' => true, 'last_seen_at' => now()],
        );

        return response()->view('games.demo-shell', [
            'code' => $template->code,
            'title' => $template->title,
            'session' => $session->token,
            'endpoint' => url("/api/game/{$template->code}/server"),
        ]);
    }
}
