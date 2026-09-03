<?php

namespace App\Services\Legacy;

use App\Services\GamePlay\BundleEntryResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Locates a legacy game's files in the local mirror and turns them into
 * rebuild assets: the engine spec (via {@see LegacyGameSpec}) and the poster
 * image (copied onto the `public` disk).
 *
 * Legacy layout (rsync'd into storage/legacy/):
 *   app-games/<Code>/{SlotSettings.php,reels.txt,Server.php}
 *   gamess/<folder>/…                (front-end bundle; folder from w_game_path or = Code)
 *   frontend/<Theme>/ico/<Code>.jpg  (poster)
 */
class LegacyGameReader
{
    /** Sentinel `game_bundles.entry` for a front-end that ships only its JS
     *  engine (js/loader.js + js/core.js) — the HTML shell is synthesised at
     *  request time by GameAssetController. */
    public const string SLOT_EVENT_SHELL = '__slot_event_shell__';

    /** memoised spec per code */
    private array $specCache = [];

    /** memoised raw SlotSettings config per code */
    private array $rawCache = [];

    /** the legacy `resources/lang/en/games.php` map — code => UI-string map */
    private ?array $languageMap = null;

    /** @param array<string, string> $paths  legacy w_game_path: code => folder */
    public function __construct(
        private readonly string $appGamesPath,
        private readonly array $paths = [],
        private readonly BundleEntryResolver $entryResolver = new BundleEntryResolver,
    ) {}

    /** @return array<string, mixed> partial game_templates attrs (may be empty) */
    public function spec(string $code): array
    {
        return $this->specCache[$code] ??= (
            LegacyGameSpec::fromDir($this->appGamesPath.'/'.$code)?->extract() ?? []
        );
    }

    /**
     * The legacy SlotSettings config the `slotEvent` front-end needs but the DB
     * import didn't keep — symbol names + cosmetic/feature literals. Empty array
     * when the game isn't in the app-games mirror.
     *
     * @return array<string, mixed>
     */
    public function slotSettings(string $code): array
    {
        return $this->rawCache[$code] ??= (
            LegacyGameSpec::fromDir($this->appGamesPath.'/'.$code)?->rawConfig() ?? []
        );
    }

    /**
     * The `slotLanguage` map the `slotEvent` front-end draws its UI labels from
     * (CREDIT / BET / WIN / "GAME OVER, PLACE YOUR BET" / the free-games banner …).
     * Legacy served `Lang::get('games.<Code>')`; that whole file is vendored at
     * `resources/legacy/game-language.php`. Falls back to the mobile twin's
     * entry, then to a shared English default, so every game gets real labels.
     *
     * @return array<string, string>
     */
    public function language(string $code): array
    {
        $this->languageMap ??= (function (): array {
            $file = resource_path('legacy/game-language.php');

            return is_file($file) ? (array) require $file : [];
        })();

        $default = $this->languageMap['Africa'] ?? [];
        $game = $this->languageMap[$code]
            ?? $this->languageMap[$code.'M']
            ?? $this->languageMap[preg_replace('/M$/', '', $code)]
            ?? [];

        return array_map('strval', array_merge($default, $game));
    }

    /** True when the game's Server.php speaks the `slotEvent` wire protocol. */
    public function usesSlotEvent(string $code): bool
    {
        $server = $this->appGamesPath.'/'.$code.'/Server.php';

        return is_file($server) && str_contains((string) file_get_contents($server), 'slotEvent');
    }

    /** Copy the poster onto the public disk; return its relative path or null. */
    public function poster(string $code): ?string
    {
        $theme = config('legacy.poster_theme', 'Default');
        $candidates = [
            config('legacy.frontend_path')."/{$theme}/ico/{$code}.jpg",
            config('legacy.frontend_path')."/Default/ico/{$code}.jpg",
            $this->bundleDir($code).'/ico.jpg',
            $this->bundleDir($code).'/poster.jpg',
        ];

        foreach ($candidates as $src) {
            if (is_file($src)) {
                $rel = "game-posters/{$code}.jpg";
                Storage::disk('public')->put($rel, (string) file_get_contents($src));

                return $rel;
            }
        }

        return null;
    }

    /** Front-end bundle directory for a game, or null if not mirrored. */
    public function bundleDir(string $code): string
    {
        $folder = $this->paths[$code] ?? $code;

        return rtrim((string) config('legacy.gamess_path'), '/')."/{$folder}";
    }

    public function hasBundle(string $code): bool
    {
        return $this->bundleEntry($code) !== null;
    }

    /**
     * The real playable entry file (bundle-dir-relative), or null. Legacy games
     * nest the entry wildly and litter the folder with browser-check / loader
     * decoys — {@see BundleEntryResolver} untangles it.
     */
    public function bundleEntry(string $code): ?string
    {
        $dir = $this->bundleDir($code);
        if (! is_dir($dir)) {
            return null;
        }

        $entry = $this->entryResolver->resolve($this->htmlFiles($dir), $code);
        if ($entry !== null) {
            return $entry;
        }

        // No HTML, but a Novomatic/Greentube JS engine is present → the shell is
        // built at request time.
        if (is_file($dir.'/js/loader.js') && is_file($dir.'/js/core.js')) {
            return self::SLOT_EVENT_SHELL;
        }

        return null;
    }

    /**
     * Every .html / .htm / .xhtml under a bundle dir (≤6 levels), as relative
     * paths. Only the HTML files matter to the resolver, so we skip the full
     * (often 10k-file) tree walk.
     *
     * @return list<string>
     */
    private function htmlFiles(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $found = [];

        for ($depth = 0; $depth <= 6; $depth++) {
            $glob = $dir.str_repeat('/*', $depth).'/*.{html,htm,xhtml,HTML,HTM}';
            foreach (glob($glob, GLOB_BRACE) ?: [] as $path) {
                $found[] = ltrim(substr($path, strlen($dir) + 1), '/');
            }
        }

        return $found;
    }
}
