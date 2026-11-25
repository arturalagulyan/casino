<?php

namespace App\Services\GamePlay\Playtech;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a Playtech (NGM HTML5) client from Betboy's static host into a
 * bundle, and the platform chrome every game shares (`commonStatic/`) into
 * the shared store.
 *
 *   https://static.joker-entry.link/pt/commonStatic/game.html   platform page (GWT "platform" module) — shared
 *   https://static.joker-entry.link/pt/<rgame>/index.html        game page (GWT "resources" module) — the bundle
 *
 * A game's files aren't listed anywhere; its resources/generic/<game>.jmp
 * names its .jmm configs, which name images (against generic/images/), sounds
 * (generic/media/), more .jmm (generic/jmm/) and .atlas files (which name
 * their page images). Each reference is tried against those bases and kept
 * where it exists. Translations are English only.
 *
 * Bundle layout: pt/<rgame>/… ; the shared store: storage/app/game-shared/playtech/commonStatic/….
 */
class PlaytechMirror
{
    public const string HOST = 'https://static.joker-entry.link/pt/';

    private const int CONCURRENCY = 16;

    /** text files whose quoted paths are followed */
    private const array TEXT = ['jmm', 'jmp', 'json', 'atlas', 'fnt', 'css'];

    private const string REF = '/["\'(]([A-Za-z0-9_.\/ -]+\.(?:png|jpg|jpeg|gif|webp|svg|mp3|ogg|m4a|wav|json|jmm|jmp|atlas|fnt|ttf|otf|woff2?|css|js|mp4|webm))["\')]/i';

    /** commonStatic files the platform loads (seen in browser sessions; it names most of them only at run time) */
    private const array COMMON = [
        'css/desktop/desktopBrand.css', 'css/desktop/desktopPlatform.css', 'css/normalize.css', 'css/style.css',
        'data/games_branding_live.json', 'data/games_list_live.json', 'data/games_location_live.json', 'data/locked_games.json',
        'fonts/Asap-Regular.ttf', 'game.html', 'game.htmlcheck.png', 'images/desktop/brand/loading_background.png',
        'images/desktop/brand/loading_brand_logo.png', 'images/desktop/brand/progress.png', 'images/desktop/icons/bag.png',
        'images/desktop/icons/settings.png', 'images/desktop/icons/volume.png', 'images/desktop/icons/volume/sound00.png',
        'images/desktop/icons/volume/sound01.png', 'images/desktop/icons/volume/sound02.png', 'images/desktop/icons/volume/sound03.png',
        'images/desktop/icons/x_button.png', 'images/desktop/left.png', 'images/desktop/loader.png', 'images/desktop/right.png',
        'images/desktop/uk/gambling_commission.jpg', 'images/game_by_pt_white.png', 'js/bootstrap.js', 'js/chat-wrapper.js', 'js/gls.js',
        'js/gls_config.js', 'js/lib/modernizr-animations.min.js', 'js/logo_resolver.js', 'js/odometer.js', 'js/script.js',
        'js/viewportJs.js', 'json/brand.json', 'json/brand_texts_en.json', 'json/config.json', 'json/default_game_config.json',
        'json/domains.json', 'json/jurisdiction.json', 'json/preload_config.js', 'json/regulations.json', 'json/texts_en.json',
        'version.json',
    ];

    private int $fetched = 0;

    private int $reused = 0;

    /** @var list<string> */
    private array $missing = [];

    /** @var callable|null */
    private $progress;

    public static function sharedDir(): string
    {
        return storage_path('app/game-shared/playtech');
    }

    /** A shared file for a bundle path (pt/commonStatic/…), or null. */
    public static function shared(string $path): ?string
    {
        $path = ltrim(preg_replace('#/+#', '/', $path), '/');
        if (! preg_match('#^(?:pt/)?(commonStatic/.+)$#', $path, $m) || str_contains($m[1], '..')) {
            return null;
        }
        $file = self::sharedDir().'/'.$m[1];

        return is_file($file) ? $file : null;
    }

    /**
     * The platform chrome, once for all games (refresh re-downloads).
     *
     * @param  list<string>  $seen  commonStatic/… paths a browser session loaded
     * @return array{fetched: int, reused: int, missing: list<string>}
     */
    public function mirrorCommon(array $seen = [], bool $refresh = false, ?callable $progress = null): array
    {
        $this->progress = $progress;
        $this->fetched = $this->reused = 0;
        $this->missing = [];
        $root = self::sharedDir();
        $c = 'commonStatic/';
        $want = array_merge(['commonStatic/frame.html', 'commonStatic/js/swipe-up.js', 'commonStatic/platform/platform.nocache.js',
            // per device (the desktop ones are in COMMON)
            'commonStatic/css/mobile/mobilePlatform.css', 'commonStatic/css/mobile/mobileBrand.css', 'commonStatic/css/tablet/tabletPlatform.css', 'commonStatic/css/tablet/tabletBrand.css'],
            array_map(fn ($p) => $c.$p, self::COMMON), $seen);
        $this->fetch($want, $root, false, $refresh);

        // the platform's GWT permutation(s), their deferred fragments, and what they name
        $nocache = $this->read($root, "{$c}platform/platform.nocache.js");
        preg_match_all('/[A-F0-9]{32}/', $nocache, $m);
        foreach (array_unique($m[0]) as $perm) {
            $this->fetch(["{$c}platform/{$perm}.cache.html"], $root, true, $refresh);
            for ($i = 1; $i < 40; $i++) {
                $rel = "{$c}platform/deferredjs/{$perm}/{$i}.cache.js";
                $this->fetch([$rel], $root, false, $refresh, $absent);
                if ($absent) {
                    break;
                }
            }
            $code = $this->read($root, "{$c}platform/{$perm}.cache.html");
            preg_match_all('/[\'"]((?:css|json|data|images|js)\/[A-Za-z0-9_.\/-]+\.(?:png|jpg|gif|svg|json|css|js))[\'"]/', $code, $r);
            $this->fetch(array_map(fn ($p) => $c.$p, $r[1]), $root, false, $refresh);
        }

        // css url(…) references, relative to each stylesheet
        foreach ($this->filesUnder($root, $c, 'css') as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', $this->read($root, $css), $r);
            $refs = [];
            foreach ($r[1] as $u) {
                if (! preg_match('#^(data:|https?:|//)#', $u)) {
                    $refs[] = self::resolve(dirname($css), strtok($u, '?#'));
                }
            }
            $this->fetch(array_filter($refs, fn ($p) => str_starts_with($p, $c)), $root, false, $refresh);
        }

        return ['fetched' => $this->fetched, 'reused' => $this->reused, 'missing' => $this->missing];
    }

    /**
     * One game's client into $outDir/pt/<rgame>/.
     *
     * @param  list<string>  $seen  <rgame>/… paths a browser session loaded (optional extra certainty)
     * @return array{fetched: int, reused: int, missing: list<string>, files: int}
     */
    public function mirror(string $rgame, string $game, string $outDir, array $seen = [], ?callable $progress = null): array
    {
        $this->progress = $progress;
        $this->fetched = $this->reused = 0;
        $this->missing = [];
        $root = rtrim($outDir, '/').'/pt';
        $g = "{$rgame}/";
        $r = "{$g}resources/";
        $this->fetch(["{$g}index.html", "{$r}resources.nocache.js"], $root, true);
        $this->fetch(array_merge(["{$r}js/viewport.js", "{$r}js/messenger.js", "{$r}js/uaparser.js", "{$r}config.json", "{$r}build.properties",
            // loaded by key, named nowhere (the DC jackpot's logo); absent from games without it
            "{$r}generic/images/dc_logo.png", "{$r}generic/images/loading_background.jpg", "{$r}generic/images/loading_screen.png"], $seen), $root, false);
        preg_match_all('/([A-F0-9]{32}[A-Za-z0-9_.-]*?)\.cache\.js|[\'"]([A-F0-9]{32}[A-Za-z0-9_.-]*)[\'"]/', $this->read($root, "{$r}resources.nocache.js"), $m);
        foreach (array_unique(array_filter(array_merge($m[1], $m[2]))) as $perm) {
            $this->fetch(["{$r}{$perm}.cache.js"], $root, true);
        }

        // paths the compiled code names itself (the DC jackpot's logo and fonts …)
        $code = [];
        foreach (array_unique(array_filter(array_merge($m[1], $m[2]))) as $perm) {
            preg_match_all('/[\'"]([A-Za-z0-9_\/-]+\/[A-Za-z0-9_.-]+\.(?:png|jpg|otf|ttf|fnt|woff2?|mp3|ogg|mp4|json|jmm))[\'"]/i', $this->read($root, "{$r}{$perm}.cache.js"), $cm);
            foreach (array_unique($cm[1]) as $ref) {
                $code[] = [$ref, ["{$r}generic/images", "{$r}generic/fonts", "{$r}generic", "{$r}generic/jmm", "{$r}generic/media"]];
            }
            // fonts named bare (Joker Jewels: fxg('bebas.ttf') — under generic/fonts)
            preg_match_all('/[\'"]([A-Za-z0-9_.-]+\.(?:otf|ttf|woff2?))[\'"]/i', $this->read($root, "{$r}{$perm}.cache.js"), $fm);
            foreach (array_unique($fm[1]) as $ref) {
                $code[] = [$ref, ["{$r}generic/fonts"]];
            }
        }
        // configs the code names (Robocop's scenes/5oak_en.jmm) are crawled like the rest
        $fromCode = array_values(array_filter($this->locate($code, $root, $r),
            fn ($rel) => in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::TEXT, true)));
        $this->missing = [];   // code strings that aren't files are expected

        // the root config is usually generic/<game>.jmp, but the code names it (Gladiator: glad2.jmp)
        $jmp = "generic/{$game}.jmp";
        foreach (array_unique(array_filter(array_merge($m[1], $m[2]))) as $perm) {
            if (preg_match('/[\'"](generic\/[A-Za-z0-9_-]+\.jmp)[\'"]/', $this->read($root, "{$r}{$perm}.cache.js"), $jm)) {
                $jmp = $jm[1];
                break;
            }
        }
        $this->fetch(["{$r}{$jmp}"], $root, true);

        // follow the config tree
        $done = [];
        $queue = array_merge(["{$r}{$jmp}"], $fromCode);
        while ($queue) {
            $file = array_shift($queue);
            if (isset($done[$file])) {
                continue;
            }
            $done[$file] = true;
            // older builds name translated files by a placeholder: autoplay_${lang}.png
            $text = str_replace('${lang}', 'en', $this->read($root, $file));
            $refs = [];
            if (str_ends_with($file, '.atlas')) {
                foreach (preg_split('/\R/', $text) as $line) {
                    if (preg_match('/^\s*([^:\s][^:]*\.(?:png|jpg|webp))\s*$/', $line, $a)) {
                        $refs[] = [trim($a[1]), [dirname($file)]];
                    }
                }
            } else {
                preg_match_all(self::REF, $text, $mm);
                foreach ($mm[1] as $ref) {
                    $refs[] = [$ref, $this->bases($r, $file, $ref)];
                }
            }
            foreach ($this->locate($refs, $root, $r) as $rel) {
                if (in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::TEXT, true) && ! isset($done[$rel])) {
                    $queue[] = $rel;
                }
            }
        }

        // bitmap fonts: generic/fonts/<name>.png goes with a <name>.txt glyph table (Batman v Superman's fg_numbers)
        if (is_dir("{$root}/{$r}generic/fonts")) {
            $tables = [];
            foreach (File::allFiles("{$root}/{$r}generic/fonts") as $f) {
                if (strtolower($f->getExtension()) === 'png') {
                    $tables[] = "{$r}generic/fonts/".preg_replace('/\.png$/i', '.txt', $f->getRelativePathname());
                }
            }
            $missing = $this->missing;
            $this->fetch($tables, $root, false);
            $this->missing = $missing;   // most have none
        }

        return ['fetched' => $this->fetched, 'reused' => $this->reused, 'missing' => $this->missing,
            'files' => count(File::allFiles("{$root}/{$rgame}"))];
    }

    /** @return list<string> directories a reference in $file may be relative to */
    private function bases(string $r, string $file, string $ref): array
    {
        $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
        $dir = dirname($file);   // (extensions compare lower-case: FRAHV.TTF)
        $gen = "{$r}generic";

        return array_values(array_unique(match (true) {
            in_array($ext, ['jmm', 'jmp'], true) => ["{$gen}/jmm", $dir, $gen],
            in_array($ext, ['mp3', 'ogg', 'm4a', 'wav', 'mp4', 'webm'], true) => ["{$gen}/media", "{$gen}/media/videos", $dir, "{$gen}/images"],
            in_array($ext, ['ttf', 'otf', 'woff', 'woff2', 'fnt'], true) => ["{$gen}/fonts", $dir, $gen, "{$gen}/images"],
            default => ["{$gen}/images", $dir, $gen, "{$gen}/media", "{$gen}/jmm", "{$r}translations/images"],
        }));
    }

    /**
     * Fetch each reference from the first base it exists under.
     *
     * @param  list<array{0: string, 1: list<string>}>  $refs
     * @return list<string> the paths found
     */
    private function locate(array $refs, string $root, string $r): array
    {
        $found = [];
        $open = [];
        foreach ($refs as [$ref, $bases]) {
            $open[$ref] = $bases;
        }
        for ($k = 0; $open && $k < 6; $k++) {
            $try = [];
            foreach ($open as $ref => $bases) {
                if (! isset($bases[$k])) {
                    unset($open[$ref]);

                    continue;
                }
                $rel = self::resolve($bases[$k], ltrim($ref, '/'));
                if (str_starts_with($rel, $r)) {
                    $try[$ref] = $rel;
                }
            }
            $this->fetch(array_values($try), $root, false, false, $absent, $miss);
            foreach ($try as $ref => $rel) {
                if (! in_array($rel, $miss, true)) {
                    $found[] = $rel;
                    unset($open[$ref]);
                }
            }
        }
        foreach (array_keys($open) as $ref) {
            $this->missing[] = $ref;
        }

        return $found;
    }

    private static function resolve(string $dir, string $rel): string
    {
        $parts = [];
        foreach (explode('/', trim($dir, '/').'/'.$rel) as $p) {
            if ($p === '..') {
                array_pop($parts);
            } elseif ($p !== '.' && $p !== '') {
                $parts[] = $p;
            }
        }

        return implode('/', $parts);
    }

    private function read(string $root, string $rel): string
    {
        $file = "{$root}/{$rel}";
        if (! is_file($file)) {
            throw new RuntimeException("Not mirrored: {$rel}");
        }

        return (string) file_get_contents($file);
    }

    /** @return list<string> */
    private function filesUnder(string $root, string $prefix, string $ext): array
    {
        $out = [];
        foreach (File::allFiles("{$root}/{$prefix}") as $f) {
            if (strtolower($f->getExtension()) === $ext) {
                $out[] = $prefix.str_replace('\\', '/', $f->getRelativePathname());
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $rels
     * @param  list<string>|null  $miss  the ones not there
     */
    private function fetch(array $rels, string $root, bool $required, bool $refresh = false, ?int &$absent = null, ?array &$miss = null): void
    {
        $absent = 0;
        $miss = [];
        $todo = [];
        foreach (array_unique($rels) as $rel) {
            if (! $refresh && is_file("{$root}/{$rel}") && filesize("{$root}/{$rel}") > 0) {
                $this->reused++;
            } else {
                $todo[] = $rel;
            }
        }
        foreach (array_chunk($todo, self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(120)
                    ->retry(3, 1000, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
                    ->get(self::HOST.str_replace(' ', '%20', $rel)),
                $chunk,
            ));
            foreach ($chunk as $rel) {
                $resp = $responses[$rel] ?? null;
                // Betboy answers a missing file with its HTML error page — only .html files may be HTML
                $html = str_contains((string) ($resp instanceof Response ? $resp->header('Content-Type') : ''), 'text/html');
                if ($resp instanceof Response && $resp->successful() && (! $html || str_ends_with($rel, '.html'))) {
                    File::ensureDirectoryExists(dirname("{$root}/{$rel}"));
                    $tmp = "{$root}/{$rel}.".getmypid().'.part';
                    File::put($tmp, $resp->body());
                    rename($tmp, "{$root}/{$rel}");
                    $this->fetched++;
                    if ($this->progress) {
                        ($this->progress)($rel);
                    }
                } else {
                    $absent++;
                    $miss[] = $rel;
                    if ($required) {
                        throw new RuntimeException("Can't fetch {$rel}: ".($resp instanceof Response ? $resp->status() : 'no response'));
                    }
                }
            }
        }
    }
}
