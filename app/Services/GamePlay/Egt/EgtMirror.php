<?php

namespace App\Services\GamePlay\Egt;

use App\Services\GamePlay\Amatic\AmaticPageRecorder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors an EGT (Amusnet) HTML5 game off its static host
 * (`https://static.joker-entry.link/eh/`), keeping the host's hierarchy:
 *
 *   index.html                              the page (bundle entry), written here:
 *                                           Betboy's launch page, its connectionParams
 *                                           completed from `window.EGT` (our socket
 *                                           host, session key, language)
 *   init.js, main.<hash>.js, lib/…          EGT's portal (game list, socket, chrome)
 *   game-ui/…, translations/…               the external UI (bet bar, menus)
 *   Mobile/lib/…, Mobile/games/assets/…     the slot runtime and shared assets
 *   Mobile/games/<Engine>/game.min.js       the game engine
 *   Mobile/games/<Engine>/assets/<gin>/…    the game
 *   paytable/<gin>/<lang>.html, sprite…     its help pages
 *
 * Files come from a browser recording of a live demo ({@see AmaticPageRecorder})
 * — the engines build their file lists in code — plus every help page and
 * translation the game ships and whatever the stylesheets reference.
 */
class EgtMirror
{
    public const string HOST = 'https://static.joker-entry.link/eh/';

    private const int CONCURRENCY = 16;

    public function __construct(private readonly AmaticPageRecorder $recorder) {}

    /**
     * @param  callable(): string  $launch  a fresh Betboy launch URL — asked for once this import holds the browser
     * @return array{entry: string, gin: int, engine: string, files: int, bytes: int, missing: list<string>, languages: list<string>}
     */
    public function mirror(callable $launch, string $outDir, ?callable $progress = null): array
    {
        // one browser at a time: the Selenium container serves a single session (parallel imports queue here)
        $progress && $progress('waiting for the browser…');
        File::ensureDirectoryExists(storage_path('app/game-fetch'));
        $lock = fopen(storage_path('app/game-fetch/.egt-recorder.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $launch = $launch();
            $progress && $progress('recording the page in a browser…');
            $recorded = $this->recorder->record($launch);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $html = Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 2000)->get($launch)->body();
        $params = EgtLiveClient::params($html);
        $gin = (int) $params['gameIdentificationNumber'];
        File::deleteDirectory($outDir);
        File::ensureDirectoryExists($outDir);

        // 1. what a live page loads, and the portal's own config files
        $wanted = ['init.js', 'asset-manifest.json', 'lib/games.config.json', 'style.css', 'game-ui/index.html', 'game-ui/game-init.js'];
        foreach ($recorded as $url) {
            if (str_starts_with($url, self::HOST)) {
                $wanted[] = rawurldecode(substr((string) parse_url($url, PHP_URL_PATH), strlen('/eh/')));
            }
        }
        $wanted = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $wanted))));
        $engine = '';
        foreach ($wanted as $rel) {
            if (preg_match("#^Mobile/games/([^/]+)/assets/{$gin}/#", $rel, $m)) {
                $engine = $m[1];
            }
        }
        if ($engine === '') {
            throw new RuntimeException("The browser recording loaded nothing of game {$gin} — did the page start?");
        }
        $missing = $this->fetch($wanted, $outDir, $progress);

        // 1b. what the page loads from Web Workers (not in the browser's network log):
        //     the texture transcoders and the compressed textures (`x.basis.ktx2`)
        //     the sprite sheets and spine atlases name
        $this->fetch(array_map(fn ($p) => "Mobile/lib/v8/transcoders/{$p}", ['ktx/libktx.js', 'ktx/libktx.wasm',
            'basis/basis_transcoder.js', 'basis/basis_transcoder.wasm']), $outDir, null, quiet: true);
        $this->fetch($this->sheetImages($outDir, ["Mobile/games/{$engine}/assets/{$gin}", 'Mobile/games/assets']), $outDir, $progress, quiet: true);

        // 2. per language: the help pages, the UI strings, the game's language-specific sheets
        $languages = [];
        $config = (array) json_decode((string) @file_get_contents("{$outDir}/lib/games.config.json"), true);
        $all = (array) ($config['gameEngines']['Translations']['languages'] ?? ['en']);
        $perLang = array_values(array_filter($wanted, fn ($p) => preg_match('#(_|/)en\.(json|png|jpg|webp|html)$#', $p)));
        foreach (array_unique(array_merge(['en'], $all)) as $lang) {
            $pages = $this->fetch(["paytable/{$gin}/{$lang}.html"], $outDir, null);
            if ($pages !== []) {
                continue;   // no help in this language: the game doesn't ship it
            }
            $languages[] = $lang;
            $extra = array_map(fn ($p) => (string) preg_replace('#(_|/)en\.(\w+)$#', '${1}'.$lang.'.$2', $p), $perLang);
            $this->fetch(array_values(array_filter($extra, fn ($p) => ! is_file("{$outDir}/{$p}"))), $outDir, null);
        }
        $progress && $progress('help pages: '.implode(',', $languages));

        // 3. what the help pages, stylesheets and the portal's / UI's modules reference
        //    (Vite builds load images lazily: `new URL("x-<hash>.png", import.meta.url)`)
        foreach ([3, 2, 1] as $round) {   // what they pull in can reference more
            $refs = [];
            foreach (File::allFiles($outDir) as $f) {
                $ext = $f->getExtension();
                $rel = str_replace('\\', '/', $f->getRelativePathname());
                if (! in_array($ext, ['css', 'html'], true) && ! ($ext === 'js' && ! str_starts_with($rel, 'Mobile/'))) {
                    continue;
                }
                $dir = dirname($rel);
                $text = (string) File::get($f->getPathname());
                $re = match ($ext) {
                    'css' => '#url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#',
                    'js' => '#(?:new URL\(\s*"([^"]+)"\s*,\s*import\.meta\.url\s*\)|\bimport\(\s*"(\./[^"]+)"\s*\))#',
                    default => '#(?:src|href)\s*=\s*"([^"]+)"#',
                };
                if ($ext === 'html') {
                    $text = html_entity_decode($text);
                }
                preg_match_all($re, $text, $m);
                $m[1] = array_filter(array_merge($m[1], $m[2] ?? []));
                foreach ($m[1] as $u) {
                    if (! preg_match('#^(data:|https?:|//|\#|javascript:|mailto:)#', $u) && ! str_contains($u, '{')) {
                        $refs[] = self::normalize(($u[0] === '/' ? '' : $dir.'/').preg_replace('/[?#].*$/', '', $u));
                    }
                }
            }
            $refs = array_values(array_filter(array_unique($refs), fn ($p) => $p !== '' && ! is_file("{$outDir}/{$p}")));
            $this->fetch($refs, $outDir, $progress, quiet: true);
        }

        File::put("{$outDir}/index.html", self::entry($html));
        self::patch($outDir);
        $files = File::allFiles($outDir, true);

        return [
            'entry' => 'index.html',
            'gin' => $gin,
            'engine' => $engine,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => array_values(array_unique($missing)),
            'languages' => $languages ?: ['en'],
        ];
    }

    /**
     * Patches to the mirrored portal, idempotent (run on fresh and reused
     * mirrors): EGT's init.js treats `localhost` as its own dev setup — it
     * skips asset-manifest.json and loads `src/index.tsx` and an `undefined`
     * stylesheet — so a game opened on http://localhost never started.
     */
    public static function patch(string $dir): void
    {
        // the portal, the external UI and the help pages all carry such dev switches
        // (`hostname === "localhost"`, `/localhost|\d/` — any host with a digit, e.g. an IP —,
        // `/localhost|(dev|demo)\.games/`): none of them may fire on our hosts
        $files = array_merge(glob("{$dir}/init.js") ?: [], glob("{$dir}/main.*.js") ?: [], glob("{$dir}/lib/game-platform.js") ?: [],
            glob("{$dir}/game-ui/assets/*.js") ?: [], glob("{$dir}/paytable/assets/*.js") ?: []);
        foreach ($files as $file) {
            $js = (string) File::get($file);
            $fixed = (string) preg_replace('/\b(?:window\.)?location\.hostname\s*===?\s*(["\'])localhost\1/', 'false', $js);
            $fixed = str_replace(['/localhost|\d/', 'localhost|'], ['/(?!)/', 'localhost.invalid|'], $fixed);
            if ($fixed !== $js) {
                File::put($file, $fixed);
            }
        }
    }

    /**
     * The bundle's page: Betboy's launch page, loading the portal relative to
     * the page, its connectionParams completed at run time from `window.EGT`
     * (which our game launch injects): `tcpHost` (the socket our shim takes
     * over), `sessionKey`, `lang`, `baseUrl` (help pages: `<baseUrl>/paytable/…`).
     * The socket runs in the page, not a worker (the shim lives in the page),
     * and the buy-bonus / double-chance offers are off: the outcome library
     * holds plain paid spins only.
     */
    public static function entry(string $html): string
    {
        $html = (string) preg_replace('#<base\s[^>]*>\s*#i', '', $html);
        $html = (string) preg_replace('#window\.SHB = "[^"]*";#', 'window.SHB = location.host;', $html);
        $html = (string) preg_replace('#window\.HELPURL = "[^"]*";#', 'window.HELPURL = "";', $html);
        $html = (string) preg_replace('#//document\.domain = "";\s*#', '', $html);
        $fix = <<<'JS'
            (function (p, s) {
                s = window.EGT || {};
                p.tcpHost = s.host || p.tcpHost;
                p.tcpPort = s.port || p.tcpPort;
                p.tcpProtocol = s.protocol || p.tcpProtocol;
                p.sessionKey = s.sessionKey || p.sessionKey;
                p.lang = s.lang || p.lang || "en";
                p.baseUrl = s.baseUrl || "";
                p.nsPlayerId = s.player || p.nsPlayerId;
                p.shouldNotUseSocketWebWorker = true;
                p.hasBuyBonus = false;
                p.maxAllowedBuyBonusBet = 0;
                p.hasDoubleChance = false;
                p.shouldShowGameHistory = false;
                p.shouldSendLaunchLobbyMessage = false;
                p.dontVisualizePortal = true;
                p.multigame = false;
            })(connectionParams);
JS;
        $html = (string) preg_replace('#(\n\s*var additionalParams)#', "\n".$fix.'$1', $html, 1, $n);
        if ($n !== 1) {
            throw new RuntimeException('EGT launch page: no connectionParams block to complete');
        }
        $html = (string) preg_replace('#base:\s*"https://static\.joker-entry\.link/eh/"#', 'base: document.baseURI.replace(/[?#].*$/, "").replace(/[^\/]*$/, "")', $html);

        return $html;
    }

    /**
     * The images the sprite sheets (`meta.image`) and spine atlases (page
     * lines) under `$dirs` name and the mirror lacks — plus, for a compressed
     * texture, its png / webp twin (engines fall back on GPUs without KTX2).
     *
     * @param  list<string>  $dirs
     * @return list<string>
     */
    private function sheetImages(string $outDir, array $dirs): array
    {
        $images = [];
        foreach ($dirs as $d) {
            if (! is_dir("{$outDir}/{$d}")) {
                continue;
            }
            foreach (File::allFiles("{$outDir}/{$d}") as $f) {
                $rel = str_replace('\\', '/', $f->getRelativePathname());
                $dir = $d.'/'.dirname($rel);
                $names = [];
                if ($f->getExtension() === 'json') {
                    $j = json_decode((string) File::get($f->getPathname()), true);
                    if (is_array($j) && is_string($j['meta']['image'] ?? null)) {
                        $names[] = $j['meta']['image'];
                    }
                    foreach ((array) ($j['meta']['related_multi_packs'] ?? []) as $pack) {
                        $names[] = (string) $pack;
                    }
                } elseif ($f->getExtension() === 'fnt') {   // bitmap fonts: <page file="…"/> (xml) or page … file="…" (text)
                    preg_match_all('/\bfile\s*=\s*"([^"]+)"/i', (string) File::get($f->getPathname()), $m);
                    $names = $m[1];
                } elseif ($f->getExtension() === 'atlas') {
                    preg_match_all('/^([^\s:][^:\r\n]*\.(?:png|jpg|webp|ktx2))\s*$/mi', (string) File::get($f->getPathname()), $m);
                    $names = $m[1];
                }
                foreach ($names as $name) {
                    $name = (string) preg_replace('/[?#].*$/', '', $name);
                    $variants = [$name];
                    if (str_ends_with($name, '.basis.ktx2') || str_ends_with($name, '.ktx2')) {
                        $stem = (string) preg_replace('/(\.basis)?\.ktx2$/', '', $name);
                        $variants = array_merge($variants, ["{$stem}.png", "{$stem}.webp", "{$stem}.jpg"]);
                    }
                    foreach ($variants as $v) {
                        $p = self::normalize($dir.'/'.$v);
                        if (! is_file("{$outDir}/{$p}")) {
                            $images[] = $p;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @param  list<string>  $rels
     * @return list<string> the ones the host did not serve
     */
    private function fetch(array $rels, string $outDir, ?callable $progress, bool $quiet = false): array
    {
        $missing = [];
        foreach (array_chunk($rels, self::CONCURRENCY) as $i => $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(60)->get(self::HOST.str_replace(' ', '%20', $rel)),
                $chunk,
            ));
            foreach ($chunk as $rel) {
                $r = $responses[$rel] ?? null;
                if (! $r instanceof Response || ! $r->successful()) {
                    $missing[] = $rel;

                    continue;
                }
                File::ensureDirectoryExists(dirname("{$outDir}/{$rel}"));
                File::put("{$outDir}/{$rel}", $r->body());
            }
            $progress && $progress(sprintf('%d / %d files', min(count($rels), ($i + 1) * self::CONCURRENCY), count($rels)));
        }

        return $quiet ? [] : $missing;
    }

    private static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '..') {
                array_pop($out);
            } elseif ($part !== '.' && $part !== '') {
                $out[] = $part;
            }
        }

        return implode('/', $out);
    }
}
