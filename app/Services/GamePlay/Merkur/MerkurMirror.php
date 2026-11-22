<?php

namespace App\Services\GamePlay\Merkur;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a Merkur (Edict) HTML5 client off Betboy's static host
 * (`https://static.joker-entry.link/mk/`), keeping the host's hierarchy:
 *
 *   iframe2.html                      Edict's game launcher (bundle entry):
 *                                     reads `game` / `lang` / `serverURL` /
 *                                     `session` off its URL; `<base>` is
 *                                     /mk/commonStatic2/ (rewritten when served)
 *   commonStatic2/…                   the launcher layer: jQuery & co.,
 *                                     gaming.lib.edict.11.0.0.js (talks to the
 *                                     server, frames the game), its CSS, its
 *                                     texts (eoc-translations/<lang>/…)
 *   <game>/html/index-mobile.html     the game (Phaser): core/…, private/game.js,
 *                                     private/lib/framework.js and every asset
 *                                     path they name, help texts per language
 *
 * Absolute `/mk/commonStatic2/` paths in the launcher's JS become relative
 * to its base, so the mirror runs under any prefix.
 *
 * Older games (ImpactJS + jQuery Mobile: private/<name>.min.js, no
 * framework.js) build most file names at run time ('…_000'+n+'.png'); they
 * preload them all at start, so a browser recording of a live page
 * (`$recorded`) supplies those.
 */
class MerkurMirror
{
    public const string HOST = 'https://static.joker-entry.link/mk/';

    /** The launcher's gaming lib: `gaming.lib.<integration type>.11.0.0.js` (type "edict"). */
    public const string GAMING_LIB = 'commonStatic2/javascripts/gaming.lib.edict.11.0.0.js';

    private const int CONCURRENCY = 16;

    private const string EXT = 'png|jpg|jpeg|webp|gif|json|mp3|ogg|m4a|wav|mp4|webm|fnt|xml|atlas|woff2?|ttf|otf|eot|svg|css|js|html|txt';

    private string $outDir = '';

    /** @var array<string, bool> host-relative path => fetched ok */
    private array $seen = [];

    /** @var list<string> */
    private array $missing = [];

    /** @var array<string, int> host-relative path => status of a failed fetch that was not a plain 403/404 (worth another try) */
    private array $flaky = [];

    private int $reported = -1;

    /** @var (callable(string): void)|null */
    private $progress;

    /**
     * @param  string  $game  Betboy's id = the game's folder, e.g. multifruits_mk_html
     * @param  list<string>  $languages  the casino's languages (texts fetched for each; missing ones are fine)
     * @param  list<string>  $recorded  absolute URLs a live page loaded (older games; see the class doc)
     * @return array{entry: string, folder: string, files: int, bytes: int, missing: list<string>, languages: list<string>, legacy: bool}
     */
    public function mirror(string $game, string $outDir, array $languages = ['en'], ?callable $progress = null, array $recorded = []): array
    {
        if (! preg_match('/^[a-z0-9_]+$/i', $game)) {
            throw new RuntimeException("Not a Merkur game folder: {$game}");
        }
        $this->outDir = rtrim($outDir, '/');
        $this->progress = $progress;
        $this->seen = $this->missing = $this->flaky = [];
        File::deleteDirectory($this->outDir);
        File::ensureDirectoryExists($this->outDir);
        $languages = array_values(array_unique(array_filter(array_map('strtolower', $languages), fn ($l) => preg_match('/^[a-z]{2}$/', $l)))) ?: ['en'];

        // 1. the launcher
        $launcher = $this->get('iframe2.html', true) ?? throw new RuntimeException('Merkur launcher not found: '.self::HOST.'iframe2.html');
        $wanted = [self::GAMING_LIB, 'commonStatic2/images/Slot-Alone.png', 'commonStatic2/images/favicon.ico'];
        preg_match_all('#\b(?:src|href)="([^"]+)"#i', $launcher, $m);
        foreach ($m[1] as $ref) {
            if (! str_contains($ref, '//') && ! str_starts_with($ref, '/') && ! str_starts_with($ref, '#') && ! str_starts_with($ref, 'javascript:')) {
                $wanted[] = 'commonStatic2/'.$ref;
            }
        }
        $this->getMany($wanted, true);
        $css = [];
        foreach (File::glob("{$this->outDir}/commonStatic2/css/*.css") as $file) {
            preg_match_all('#url\(\s*["\']?([^"\')?\#]+)#i', (string) file_get_contents($file), $u);
            foreach ($u[1] as $ref) {
                if (! str_starts_with($ref, 'data:') && ! str_contains($ref, '//')) {
                    $css[] = $this->resolve('commonStatic2/css', $ref);
                }
            }
        }
        $this->getMany($css, false);
        $help = preg_replace('/_/', '-', $game, 1);   // the gaming lib: currentGameKey.replace('_', '-')
        $this->getMany(array_merge(
            array_map(fn ($l) => "commonStatic2/eoc-translations/{$l}/eoc-translations.html", $languages),
            array_map(fn ($l) => "commonStatic2/eoc-content/{$l}/eoc-help-content-mobile/spiele/{$help}.html", $languages),
        ), false);
        $lib = $this->read(self::GAMING_LIB);
        if (! str_contains($lib, "'/mk/commonStatic2/")) {
            throw new RuntimeException('The Merkur gaming lib no longer names /mk/commonStatic2/ — check the launcher patch');
        }
        File::put("{$this->outDir}/".self::GAMING_LIB, str_replace("'/mk/commonStatic2/", "'", $lib));

        // 2. the game
        $root = "{$game}/html";
        $index = $this->get("{$root}/index-mobile.html", true) ?? throw new RuntimeException("Merkur game page not found: {$root}/index-mobile.html");
        preg_match_all('#\b(?:src|href)="([^"\#?]+)#i', $index, $m);   // without a cache-busting query (framework.js?v=…)
        $this->getMany(array_values(array_map(fn ($s) => $this->resolve($root, $s),
            array_filter($m[1], fn ($s) => ! str_contains($s, '//') && ! str_starts_with($s, '/') && preg_match('#\.(?:'.self::EXT.')$#i', $s)))), true);
        $legacy = ! str_contains($index, 'framework.js');
        $seed = [];
        foreach ($recorded as $url) {
            if (str_starts_with($url, self::HOST)) {
                $seed[] = rawurldecode(substr((string) parse_url($url, PHP_URL_PATH), strlen((string) parse_url(self::HOST, PHP_URL_PATH))));
            }
        }
        $this->getMany(array_values(array_filter(array_unique($seed), fn ($r) => $r !== '' && ! str_ends_with($r, '/'))), false);
        $this->getMany(["{$root}/core/branding/language-independent/images/customer_logo.png", "{$root}/private/lib/lib/templates/iom/help.js"], false);
        $this->getMany(array_merge(
            array_map(fn ($l) => "{$root}/private/help/iom/{$l}/game.json", $languages),
            array_map(fn ($l) => "{$root}/private/lib/lib/help/iom/{$l}/common.json", $languages),
        ), false);

        // every quoted asset path the scripts name (relative to html/): both
        // resolutions (gfx/hr, gfx/lr), every sound format
        $wanted = [];
        foreach (File::allFiles("{$this->outDir}/{$root}") as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            // linear on purpose: a lazy match over a 1 MB one-line framework.js runs out of backtracking (no matches, no error)
            $this->matchAll('#["\']((?:private|core)/[^"\'\s]{1,300})["\']#', (string) file_get_contents($file->getPathname()), $p);
            foreach ($p[1] as $path) {
                if (preg_match('#\.(?:'.self::EXT.')$#i', $path)) {
                    $wanted[] = "{$root}/{$path}";
                }
            }
        }
        $this->getMany(array_values(array_unique($wanted)), false);

        // what the stylesheets and the help pages name, relative to themselves
        $pages = [];
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (preg_match('/\.(css|html)$/i', $rel) && $rel !== 'iframe2.html') {
                $pages[] = $rel;
            }
        }
        $refs = [];
        foreach ($pages as $rel) {
            preg_match_all('#(?:url\(\s*["\']?|\b(?:src|href)=["\'])([^"\')?\#]+)#i', $this->read($rel), $u);
            foreach ($u[1] as $ref) {
                if (str_starts_with($ref, '/mk/')) {   // the older help pages name the host's paths (rewritten when served)
                    $refs[] = $this->resolve('', substr($ref, 4));
                } elseif (! str_starts_with($ref, 'data:') && ! str_contains($ref, '//') && ! str_starts_with($ref, '/') && preg_match('#\.(?:'.self::EXT.')$#i', $ref)) {
                    $refs[] = $this->resolve(dirname($rel), $ref);
                }
            }
        }
        $this->getMany(array_values(array_unique($refs)), false);

        // 3. what atlases / sound sprites / fonts name as files, relative to themselves
        $queue = array_keys(array_filter($this->seen));
        for ($round = 0; $round < 3 && $queue !== []; $round++) {
            $next = [];
            foreach ($queue as $rel) {
                foreach ($this->fileRefs($rel) as $ref) {
                    $next[] = $this->resolve(dirname($rel), $ref);
                }
            }
            $before = array_keys(array_filter($this->seen));
            $this->getMany(array_values(array_unique($next)), false);
            $queue = array_values(array_diff(array_keys(array_filter($this->seen)), $before));
        }

        if ($legacy) {
            $this->completeSequences($root);
        }

        // what failed on the network (not a 404) gets two more tries — a busy host drops requests
        for ($pass = 0; $pass < 2 && $this->flaky !== []; $pass++) {
            sleep(2);
            $retry = array_keys($this->flaky);
            foreach ($retry as $rel) {
                unset($this->seen[$rel]);
            }
            $this->getMany($retry, false);
        }
        foreach ($this->flaky as $rel => $status) {
            $this->missing[] = "{$rel} (HTTP {$status})";
        }

        $files = File::allFiles($this->outDir, true);
        $have = array_values(array_filter($languages, fn ($l) => is_file("{$this->outDir}/{$root}/private/help/iom/{$l}/game.json")
            || is_file("{$this->outDir}/commonStatic2/eoc-translations/{$l}/eoc-translations.html")));

        return [
            'entry' => 'iframe2.html',
            'folder' => $game,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => array_values(array_unique($this->missing)),
            'languages' => $have ?: ['en'],
            'legacy' => $legacy,
        ];
    }

    /**
     * Whether the game builds Edict's SplitCoinWagerFacade: its "coin" is the
     * total bet, split over the lines (else the bet per line). Read off the
     * game's script (a mirrored copy under `$dir`, else the host's); null for
     * the older games, which have none — there, one fixed line set means split
     * coins ({@see MerkurLiveClient::splitCoins()}).
     */
    public static function splitCoins(string $game, ?string $dir = null): ?bool
    {
        $rel = "{$game}/html/private/game.js";
        $js = $dir !== null && is_file("{$dir}/{$rel}") ? (string) file_get_contents("{$dir}/{$rel}")
            : Http::withUserAgent('Mozilla/5.0')->timeout(60)->retry(2, 500, throw: false)->get(self::HOST.$rel)->body();

        return str_contains($js, 'WagerFacade') ? str_contains($js, 'SplitCoinWagerFacade') : null;
    }

    /** Whether the game is one of the older ImpactJS clients (no Phaser framework.js). */
    public static function isLegacy(string $game): bool
    {
        $html = Http::withUserAgent('Mozilla/5.0')->timeout(60)->retry(2, 500, throw: false)->get(self::HOST."{$game}/html/index-mobile.html")->body();
        if (! str_contains($html, '<script')) {
            throw new RuntimeException("Merkur game page not found: {$game}/html/index-mobile.html");
        }

        return ! str_contains($html, 'framework.js');
    }

    /**
     * The older games' frame sequences (`…_00001.png` …): the recording can
     * miss frames loaded late. Every numbered run mirrored so far, and every
     * `'<prefix>' + n + '<suffix>'` the scripts build, is probed on from its
     * highest number (and from 0) until a block of 16 brings nothing.
     */
    private function completeSequences(string $root): void
    {
        $groups = [];   // "<prefix>\0<width>\0<suffix>" => highest number found
        $members = [];  // … => frames found (a lone numbered name is no sequence: glow_140x128.png)
        $add = function (string $prefix, int $width, string $suffix, int $n, int $weight = 1) use (&$groups, &$members) {
            $k = "{$prefix}\0{$width}\0{$suffix}";
            $groups[$k] = max($groups[$k] ?? -1, $n);
            $members[$k] = ($members[$k] ?? 0) + $weight;
        };
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (str_starts_with($rel, "{$root}/") && preg_match('#^(.*?)(\d{2,})(\.(?:png|jpe?g|webp))$#i', $rel, $m)) {
                $add($m[1], strlen($m[2]), $m[3], (int) $m[2]);
            }
        }
        // `'private/…/x_000' + i + '.png'`, `PATH + "Gfx/x_00" + pad(i) + ".png"`: runs none of
        // whose frames were recorded. A fragment not rooted at private/ hangs off a mirrored
        // directory holding its first segment.
        $dirs = [];
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (str_starts_with($rel, "{$root}/")) {
                $dirs[dirname($rel).'/'] = true;
            }
        }
        $anchor = function (string $fragment) use ($root, $dirs): array {
            if (preg_match('#^(?:private|core)/#', $fragment)) {
                return ["{$root}/{$fragment}"];
            }
            $fragment = ltrim($fragment, './');
            $first = explode('/', $fragment)[0];
            $out = [];
            foreach (array_keys($dirs) as $dir) {
                if (($at = strpos($dir, "/{$first}/")) !== false) {
                    $out[] = substr($dir, 0, $at + 1).$fragment;
                }
            }

            return array_values(array_unique($out));
        };
        $prefixes = [];
        $literals = [];
        foreach (File::allFiles("{$this->outDir}/{$root}") as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $js = (string) file_get_contents($file->getPathname());
            // 'dir/x_' + n + '_290x274.png', PATH + "Gfx/x_00" + pad(i) + ".png"
            $this->matchAll('#([\'"])([\w\-./]*/[\w\-.]*)\1\s*\+\s*[^+;\'"]{1,60}?\+\s*([\'"])([\w\-.]{0,60}\.(?:png|jpe?g|webp))\3#i', $js, $p, PREG_SET_ORDER);
            foreach ($p as $m) {
                foreach ($anchor($m[2]) as $prefix) {
                    $prefixes["{$prefix}\0{$m[4]}"] = true;
                }
            }
            // PATH + "FreegameGfx/x.png": a relative literal hung off a constant
            $this->matchAll('#["\']([\w\-]+/[\w\-./]{0,200}\.(?:png|jpe?g|webp|json|mp3|ogg|m4a))["\']#i', $js, $p);
            foreach ($p[1] as $fragment) {
                if (! preg_match('#^(?:private|core)/#', $fragment)) {
                    array_push($literals, ...$anchor($fragment));
                }
            }
        }
        $this->getMany(array_values(array_unique($literals)), false);
        foreach (array_keys($prefixes) as $key) {
            [$prefix, $suffix] = explode("\0", $key);
            foreach ([1, 2, 3] as $width) {
                $probe = array_map(fn ($n) => $prefix.sprintf("%0{$width}d", $n).$suffix, range(0, 3));
                $this->getMany($probe, false);
                foreach ($probe as $n => $rel) {
                    if (! ($this->seen[$rel] ?? false)) {
                        continue;
                    }
                    if (str_starts_with($suffix, '.') && preg_match('#^(.*?)(\d+)(\.\w+)$#', $rel, $parts)) {
                        $add($parts[1], strlen($parts[2]), $parts[3], (int) $parts[2], 2);   // '_00' + pad(i): the file's whole number
                    } else {
                        $add($prefix, $width, $suffix, $n, 2);
                    }
                }
            }
        }
        foreach ($groups as $k => $max) {
            if ($members[$k] < 2) {
                continue;
            }
            [$prefix, $width, $suffix] = explode("\0", $k);
            $fmt = fn (int $n) => $prefix.sprintf('%0'.(int) $width.'d', $n).$suffix;
            $this->getMany(array_map($fmt, range(0, max(0, $max))), false);   // gaps below
            for ($from = $max + 1; ; $from += 16) {
                $block = array_map($fmt, range($from, $from + 15));
                $this->getMany($block, false);
                if (array_filter($block, fn ($rel) => $this->seen[$rel] ?? false) === []) {
                    break;
                }
            }
        }
    }

    /** @return list<string> file names an atlas / sound sprite / bitmap font refers to */
    private function fileRefs(string $rel): array
    {
        $text = $this->read($rel);
        $refs = [];
        if (str_ends_with($rel, '.json')) {
            $json = json_decode($text, true);
            if (is_array($json)) {
                if (is_string($json['meta']['image'] ?? null)) {
                    $refs[] = $json['meta']['image'];
                }
                foreach (['resources', 'urls', 'src'] as $k) {   // audio sprites
                    foreach ((array) ($json[$k] ?? []) as $v) {
                        if (is_string($v)) {
                            $refs[] = $v;
                        }
                    }
                }
                foreach ((array) ($json['textures'] ?? []) as $t) {   // multi-atlas
                    if (is_array($t) && is_string($t['image'] ?? null)) {
                        $refs[] = $t['image'];
                    }
                }
            }
        } elseif (preg_match('/\.(fnt|xml|atlas)$/i', $rel)) {
            preg_match_all('#(?:file="|^)([^"\n]+\.(?:png|jpg))#mi', $text, $m);
            $refs = $m[1];
        }

        return array_values(array_unique(array_filter(array_map('trim', $refs),
            fn ($r) => $r !== '' && ! str_contains($r, '..') && ! str_contains($r, '//') && preg_match('#\.(?:'.self::EXT.')$#i', $r))));
    }

    /** preg_match_all that fails loudly (a backtracking or JIT limit otherwise just finds nothing). */
    private function matchAll(string $pattern, string $subject, ?array &$matches, int $flags = PREG_PATTERN_ORDER): void
    {
        if (preg_match_all($pattern, $subject, $matches, $flags) === false) {
            throw new RuntimeException('Scanning a script failed: '.preg_last_error_msg());
        }
    }

    private function resolve(string $dir, string $rel): string
    {
        $parts = [];
        foreach (explode('/', trim($dir, '/').'/'.ltrim($rel, '/')) as $p) {
            if ($p === '..') {
                array_pop($parts);
            } elseif ($p !== '.' && $p !== '') {
                $parts[] = $p;
            }
        }

        return implode('/', $parts);
    }

    private function read(string $rel): string
    {
        $path = "{$this->outDir}/{$rel}";

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function get(string $rel, bool $required): ?string
    {
        $this->getMany([$rel], $required);

        return ($this->seen[$rel] ?? false) ? $this->read($rel) : null;
    }

    /** @param list<string> $rels */
    private function getMany(array $rels, bool $required): void
    {
        $rels = array_values(array_filter(array_unique($rels), fn ($r) => ! isset($this->seen[$r])));
        foreach (array_chunk($rels, self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(60)->retry(2, 500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
                    ->get(self::HOST.str_replace(' ', '%20', $rel)),
                $chunk,
            ));
            foreach ($chunk as $rel) {
                $r = $responses[$rel] ?? null;
                $ok = $r instanceof Response && $r->successful() && ! $this->isHtmlFallback($rel, $r);
                $this->seen[$rel] = $ok;
                $status = $r instanceof Response ? $r->status() : 0;
                if (! $ok && ! in_array($status, [403, 404], true) && ! ($r instanceof Response && $this->isHtmlFallback($rel, $r))) {
                    $this->flaky[$rel] = $status;   // a connection error or a 5xx: tried again at the end
                } else {
                    unset($this->flaky[$rel]);
                }
                if ($ok) {
                    File::ensureDirectoryExists(dirname("{$this->outDir}/{$rel}"));
                    File::put("{$this->outDir}/{$rel}", $r->body());
                } elseif ($required) {
                    $this->missing[] = $rel;
                }
            }
            $count = count(array_filter($this->seen));
            if ($this->progress && $count !== $this->reported) {
                ($this->progress)(sprintf('%d files', $this->reported = $count));
            }
        }
    }

    /** A host answering a missing file with an HTML page, not the file. */
    private function isHtmlFallback(string $rel, Response $r): bool
    {
        return ! str_ends_with($rel, '.html') && str_contains((string) $r->header('Content-Type'), 'text/html');
    }
}
