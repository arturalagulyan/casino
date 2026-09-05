<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBundle;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Jackpot;
use App\Models\User;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\Protocol\GamePlatformLobby;
use App\Services\Legacy\LegacyGameReader;
use App\Services\SeamlessWallet\GameLaunch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;

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

        // Novomatic / Greentube bundle — ships only its JS engine, no HTML. Build
        // the loader shell at request time (the bundle is never modified).
        if ($bundle->entry === LegacyGameReader::SLOT_EVENT_SHELL || $this->isEngineOnlyBundle($bundle)) {
            return $this->slotEventShell($request, $template, $game, $session, $bundle, $user);
        }

        // Pragmatic Play bundle — a compiled GWT "platform" chrome app that
        // boots a nested "bib" game app. The top-level page legacy served was
        // never a bundle file (it was a per-game Blade view); synthesise it.
        if ((new GameConfig($template, $game))->clientProtocol() === ClientProtocol::Pragmatic) {
            return $this->pragmaticShell($template, $game, $session, $user);
        }

        $rel = $bundle->filePath($bundle->entry) ?? abort(500, 'Bundle entry file missing.');
        $html = $bundle->disk()->get($rel);

        // Legacy bundles nest the entry (amarent/index.html, gs2c/html5Game.html,
        // app/<slug>/index.html, …). Relative asset URLs inside it must resolve
        // against the entry's own directory, not the bundle root. Bundles that
        // ship their own <base> (EGT's "/games/<Code>/html5/") are left alone —
        // `code` is unchanged, so those absolute paths still hit the asset route.
        $entryDir = trim(str_replace('\\', '/', dirname((string) $bundle->entry)), '/.');
        if ($entryDir !== '' && ! preg_match('/<base\s/i', $html)) {
            $base = rtrim(url("/games/{$code}/{$entryDir}"), '/').'/';
            $html = $this->injectHead($html, "<base href=\"{$base}\">");
        }

        // WebSocket bundles (EGT GamePlatform, …) identify the player by a
        // `sessionId` in the page, not a POST endpoint. Force this launch's token
        // in (overwriting any stale value the tab's sessionStorage kept).
        $config = new GameConfig($template, $game);
        if ($config->clientProtocol()->usesWebSocket()) {
            $token = json_encode($session->token);
            $inject = "<script>try{sessionStorage.setItem('sessionId',{$token});}catch(e){}</script>";
            $inject .= $this->jackpotTickerSnippet($game, $user);
            $html = $this->injectHead($html, $inject);

            // The bundle hard-codes a stale `gameIdentificationNumber` (legacy
            // reused "546" across games). Rewrite it to the gin our login/lobby
            // will actually return for this game, so the client finds its entry
            // instead of dropping to the portal / "game unavailable".
            $gin = app(GamePlatformLobby::class)->gin($config);
            $html = preg_replace(
                '/(gameIdentificationNumber\s*[:=]\s*["\']?)\d+(["\']?)/',
                '${1}'.$gin.'${2}',
                $html,
                1,
            ) ?? $html;

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

        // EGT portal bundles ship only `library-v<n>.json`; the client asks for
        // `library-single-game-v<n>.json` once its game list has one entry. Alias
        // it at request time so nothing has to be written into the bundle.
        if (! $rel && preg_match('#(^|/)assets/library-single-game-(v\d+\.\w+)$#', $path, $m)) {
            $rel = $bundle->filePath(str_replace('library-single-game-', 'library-', $path))
                ?? $bundle->filePath('html5/assets/library-'.$m[2]);
        }

        if (! $rel) {
            abort(404);
        }

        $disk = $bundle->disk();

        return response($disk->get($rel), 200, [
            'Content-Type' => $this->mimeType($rel) ?? ($disk->mimeType($rel) ?: 'application/octet-stream'),
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    // ---- helpers ----------------------------------------------------

    /**
     * Extension-based MIME lookup. Legacy loader scripts build up HTML
     * (`document.write('<script>…</script>')`) as string literals, which
     * makes content-sniffing (`Storage::mimeType()`, backed by finfo/libmagic)
     * misdetect plain `.js`/`.css` files as `text/html`. Combined with the
     * `X-Content-Type-Options: nosniff` header, browsers then silently refuse
     * to execute the script. Trust the extension for known text asset types.
     */
    private function mimeType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;
    }

    private function isEngineOnlyBundle(GameBundle $bundle): bool
    {
        return $bundle->filePath('js/loader.js') !== null
            && $bundle->filePath('js/core.js') !== null
            && $bundle->filePath($bundle->entry) === null;
    }

    /**
     * Render the Novomatic/Greentube loader HTML (js/core.js POSTs `{slotEvent}`
     * to `/game/{code}/server`). Script order + canvas match the legacy
     * server-generated page; only class files that exist in the bundle are
     * included (the `*GT` variant drops a couple).
     */
    private function slotEventShell(Request $request, GameTemplate $template, Game $game, GameSession $session, GameBundle $bundle, User $user): Response
    {
        $config = new GameConfig($template, $game);

        $libs = collect($bundle->disk()->files($bundle->path.'/js/lib'))
            ->map(fn ($p) => 'js/lib/'.basename($p))
            ->reject(fn ($p) => str_ends_with($p, '.map'))
            ->sortBy(fn ($p) => match (true) {          // font loader → pixi core → pixi plugins → the rest
                str_contains($p, 'webfont') => 0,
                str_ends_with($p, 'pixi.min.js') => 1,
                str_contains($p, 'pixi') => 2,
                default => 3,
            })
            ->values();
        // The newer Greentube `*GT`/`*DX` bundles render with PIXI and append
        // their own <canvas>; the older Novomatic engine draws into #game.
        $pixi = $libs->contains(fn ($p) => str_ends_with($p, 'pixi.min.js'));
        if ($libs->isEmpty()) {
            $libs = collect(['js/lib/createjs.min.js']);
        }

        $classes = ['GameButton', 'GameBack', 'GameUI', 'GameView', 'GameReels', 'GameLines', 'GameCounters', 'GameRules'];
        if ($config->hasGamble()) {
            $classes[] = 'GameGamble';
        }
        if ($config->hasFreeSpins() || $config->hasBonus()) {
            $classes[] = 'GameBonus';
        }
        $classes[] = 'GameMessages';

        $scripts = $libs->all();
        foreach ($classes as $c) {
            if ($bundle->filePath("js/classes/{$c}.js")) {
                $scripts[] = "js/classes/{$c}.js";
            }
        }
        foreach (['js/utils.js', 'js/loader.js', 'js/core.js', 'js/classes/Sounds.js'] as $s) {
            if ($bundle->filePath($s)) {
                $scripts[] = $s;
            }
        }

        // The PIXI engine's loader gates every frame on a global `isFontLoaded`
        // that the legacy host page set via WebFont; synthesise it from the
        // bundle's own css/fonts.css @font-face names.
        $fontsCss = $bundle->filePath('css/fonts.css') ? 'css/fonts.css' : null;
        $fontFamilies = [];
        if ($pixi && $fontsCss && ($raw = $bundle->disk()->get($bundle->path.'/css/fonts.css'))) {
            preg_match_all('/font-family:\s*[\'"]([^\'"]+)[\'"]/i', $raw, $m);
            $fontFamilies = array_values(array_unique($m[1]));
        }

        return response()->view('games.slot-event-shell', [
            'title' => $game->title ?? $template->title,
            'base' => rtrim(url("/games/{$template->code}"), '/').'/',
            'token' => $session->token,
            'scripts' => $scripts,
            'fontsCss' => $fontsCss,
            'canvas' => ! $pixi,
            'fontFamilies' => $fontFamilies,
            'width' => 750,
            'height' => 630,
            'jackpotTicker' => $this->jackpotTickerSnippet($game, $user),
        ]);
    }

    /**
     * The legacy per-game Blade shell (`resources/views/frontend/games/list/<Code>.blade.php`
     * on the legacy server) — identical boilerplate across every Pragmatic
     * title bar `$game->name` / `$game->title`, so synthesised generically
     * instead of shipping ~60 near-duplicate Blade files. Base href points at
     * the bundle's `platform/` folder (the GWT "chrome" app, not the game
     * itself); it boots and internally loads the nested "bib" game app.
     */
    private function pragmaticShell(GameTemplate $template, Game $game, GameSession $session, User $user): Response
    {
        return response()->view('games.pragmatic-shell', [
            'title' => $game->title ?? $template->title,
            'base' => rtrim(url("/games/{$template->code}/platform"), '/').'/',
            'sessionsPlayerKey' => 'bs='.$template->code,
            'token' => $session->token,
            'jackpotTicker' => $this->jackpotTickerSnippet($game, $user),
        ]);
    }

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
        $head .= $this->jackpotTickerSnippet($game, $user);

        // Relative asset paths in the bundle resolve against the asset route,
        // not the launch URL — unless the bundle ships its own <base> (or a
        // nested-entry <base> was already injected by play()).
        if (! preg_match('/<base\s/i', $html)) {
            $base = rtrim(url("/games/{$code}"), '/').'/';
            $head = "<base href=\"{$base}\">".$head;
        }

        return $this->injectHead($html, $head);
    }

    /**
     * Jackpots this game should show a live ticker for, plus the data the
     * frontend ticker (public/js/jackpot-ticker.js) needs to render + subscribe.
     *
     * @return array{userId: int, jackpots: list<array{id: int, name: string, balance: float, currency: string}>}
     */
    private function jackpotBootstrap(Game $game, User $user): array
    {
        $game->loadMissing('shop');

        $jackpots = Jackpot::query()
            ->where('is_active', true)
            ->applicableTo($game->shop, $game->jackpot_id)
            ->get(['id', 'name', 'balance', 'currency', 'shop_id']);

        return [
            'userId' => $user->id,
            'jackpots' => $jackpots->map(fn (Jackpot $j) => [
                'id' => $j->id,
                'name' => $j->name,
                'balance' => (float) $j->balance,
                'currency' => $j->poolCurrency()->value,
            ])->values()->all(),
        ];
    }

    /** HTML to inject in <head> for the live jackpot ticker; '' if nothing applies. */
    private function jackpotTickerSnippet(Game $game, User $user): string
    {
        $bootstrap = $this->jackpotBootstrap($game, $user);

        if (empty($bootstrap['jackpots'])) {
            return '';
        }

        $json = json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $src = e(asset('js/jackpot-ticker.js'));

        return "<script>window.CasinoJackpots={$json};</script><script src=\"{$src}\" defer></script>";
    }

    /** Insert markup right after <head> (or prepend it if the doc has no head). */
    private function injectHead(string $html, string $markup): string
    {
        return preg_match('/<head[^>]*>/i', $html)
            ? (string) preg_replace('/(<head[^>]*>)/i', '$1'.addcslashes($markup, '\\$'), $html, 1)
            : $markup.$html;
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
