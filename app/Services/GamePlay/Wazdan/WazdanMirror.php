<?php

namespace App\Services\GamePlay\Wazdan;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a Wazdan HTML5 (GWT) client off its static host
 * (`https://static.joker-entry.link/wz/`), keeping the host's hierarchy.
 *
 * Per game — the bundle ($outDir):
 *
 *   index.html                   Betboy's launch page, made relative; play()
 *                                puts the session's SID2 in at serve time
 *   <game>_wzdn/style.css, <x>.nocache.js, <HASH>.cache.js …
 *                                the GWT loader and its permutations (webkit, gecko)
 *   help/desktop.html, help/mobile.html
 *                                the rules page the host's get.php serves
 *
 * Shared by every Wazdan game ($sharedDir, served when a bundle lacks a path):
 *
 *   assets/<NNN>/<md5>.<ext>     content-addressed: NNN = the md5's first byte.
 *                                Each permutation embeds a manifest of
 *                                `name:md5,size` lines; that's the file list
 *   common/…                     run.js, styles.css, icons, api.json
 *   _common/…, wz40/<game>/…     what the rules pages name
 *
 * Skipped from the manifest: the "extreme lite" asset set, promotions (drops,
 * tournaments — never offered here) and the voice-over in other languages.
 */
class WazdanMirror
{
    public const string HOST = 'https://static.joker-entry.link/wz/';

    private const int CONCURRENCY = 16;

    /** manifest prefixes never mirrored */
    private const array SKIP = ['extreme_lite/', 'promotion/'];

    private string $outDir = '';

    private string $sharedDir = '';

    /** @var list<string> */
    private array $missing = [];

    private int $fetched = 0;

    private int $reused = 0;

    /** @var (callable(string): void)|null */
    private $progress;

    /**
     * @param  string  $html  Betboy's launch page (the client's own page)
     * @param  array<string, string>  $help  the host's rules pages by device type (desktop, mobile)
     * @return array{entry: string, folder: string, module: string, permutations: list<string>, files: int, bytes: int, assets: int, absent: int, fetched: int, reused: int, missing: list<string>, title: string}
     */
    public function mirror(string $html, array $help, string $outDir, string $sharedDir, ?callable $progress = null): array
    {
        $this->outDir = rtrim($outDir, '/');
        $this->sharedDir = rtrim($sharedDir, '/');
        $this->progress = $progress;
        $this->missing = [];
        $this->fetched = $this->reused = 0;
        File::deleteDirectory($this->outDir);
        File::ensureDirectoryExists($this->outDir);
        File::ensureDirectoryExists($this->sharedDir);

        // 1. the launch page names the game folder and its GWT module
        if (! preg_match('#'.preg_quote(self::HOST, '#').'([a-z0-9_]+)/([A-Za-z0-9_]+)\.nocache\.js#', $html, $m)) {
            throw new RuntimeException('Not a Wazdan launch page: no <game>/<module>.nocache.js');
        }
        [, $folder, $module] = $m;
        $title = preg_match('#<title>([^<]*)</title>#i', $html, $t) ? trim(html_entity_decode($t[1])) : $folder;

        // 2. the loader and its permutations — the bundle's own files
        $this->fetch(["{$folder}/style.css", "{$folder}/{$module}.nocache.js"], $this->outDir, true);
        $nocache = $this->read("{$folder}/{$module}.nocache.js");
        preg_match_all("#'([0-9A-F]{32})'#", $nocache, $p);
        $permutations = array_values(array_unique($p[1]));
        if ($permutations === []) {
            throw new RuntimeException("{$folder}/{$module}.nocache.js names no permutation");
        }
        $this->fetch(array_map(fn ($h) => "{$folder}/{$h}.cache.js", $permutations), $this->outDir, true);
        $this->fetch(["{$folder}/clear.cache.gif"], $this->outDir, false);

        // 3. what the page and the loader share with every Wazdan game
        $common = ['common/run.js', 'common/styles.css', 'common/favicon.png', 'common/apple-touch-icon.png', 'common/api.json'];
        $this->fetch($common, $this->sharedDir, false, refresh: true);
        $this->fetch(array_merge($this->cssRefs($this->sharedDir, 'common/styles.css'), $this->cssRefs($this->outDir, "{$folder}/style.css")), $this->sharedDir, false);

        // 4. every asset the permutations' manifests name
        $assets = [];
        foreach ($permutations as $h) {
            foreach (self::manifest($this->read("{$folder}/{$h}.cache.js")) as $name => [$md5, $ext]) {
                if (self::wanted($name)) {
                    $assets[self::assetPath($md5, $ext)] = true;
                }
            }
        }
        if ($assets === []) {
            throw new RuntimeException("{$folder}: the client's manifest lists no assets");
        }
        // Betboy's host carries only what the client really loads (no mp3 twin of
        // an ogg, no png of a webp, …) — a 404 there is a file nobody fetches
        $this->fetch(array_keys($assets), $this->sharedDir, false, absent: $absent);

        // 5. the rules pages, and what they name (relative to the host's /wz/)
        foreach ($help as $type => $page) {
            File::ensureDirectoryExists("{$this->outDir}/help");
            File::put("{$this->outDir}/help/{$type}.html", $page);
            preg_match_all('#(?:src|href)\s*=\s*["\']([^"\'\#?]+)#i', $page, $r);
            $refs = array_values(array_filter(array_unique($r[1]), fn ($u) => ! str_contains($u, '//') && ! str_starts_with($u, 'data:') && ! str_contains($u, '..')));
            $refs = array_map(fn ($u) => ltrim($u, '/'), $refs);
            $this->fetch($refs, $this->sharedDir, false);
            foreach ($refs as $css) {
                if (str_ends_with($css, '.css')) {
                    $this->fetch($this->cssRefs($this->sharedDir, $css), $this->sharedDir, false);
                }
            }
        }

        // 6. the page: relative to the bundle, SID2 left for play() to fill in
        $page = (string) preg_replace('#<base\b[^>]*>\s*#i', '', $html);
        $page = (string) preg_replace('#var SID2 = \[[^\]]*\];#', 'var SID2 = [];', $page);
        $page = str_replace(self::HOST, '', $page);
        File::put("{$this->outDir}/index.html", $page);

        $files = File::allFiles($this->outDir, true);

        return [
            'entry' => 'index.html',
            'folder' => $folder,
            'module' => $module,
            'permutations' => $permutations,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'assets' => count($assets),
            'absent' => $absent,
            'fetched' => $this->fetched,
            'reused' => $this->reused,
            'missing' => array_values(array_unique($this->missing)),
            'title' => $title,
        ];
    }

    /**
     * The `name:md5,sizeKB` lines a permutation embeds.
     *
     * @return array<string, array{0: string, 1: string}> name => [md5, ext]
     */
    public static function manifest(string $js): array
    {
        preg_match_all('#(?:\\\\n|[\'"])([\w./\-]+\.(\w+)):([0-9a-f]{32}),\d+#', $js, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $ext, $md5]) {
            $out[$name] = [$md5, $ext];
        }

        return $out;
    }

    public static function wanted(string $name): bool
    {
        foreach (self::SKIP as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        // the voice-over: English only (the rest is ~40 MB a game)
        return ! str_starts_with($name, 'audio/lector/') || str_starts_with($name, 'audio/lector/en/');
    }

    /** The shared store every Wazdan game's bundle falls back to. */
    public static function sharedDir(): string
    {
        return storage_path('app/game-shared/wazdan');
    }

    /** A host-relative path's file in the shared store, if there (never outside it). */
    public static function shared(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || ! preg_match('#^(assets|common|_common|wz40)/#', $path)) {
            return null;
        }
        $file = self::sharedDir().'/'.$path;

        return is_file($file) ? $file : null;
    }

    public static function assetPath(string $md5, string $ext): string
    {
        return sprintf('assets/%03d/%s.%s', hexdec(substr($md5, 0, 2)), $md5, $ext);
    }

    /** @return array<int, string> host-relative files a stylesheet names by url(…) */
    private function cssRefs(string $root, string $rel): array
    {
        $css = is_file("{$root}/{$rel}") ? (string) file_get_contents("{$root}/{$rel}") : '';
        preg_match_all('#url\(\s*["\']?([^"\')?\#]+)#i', $css, $u);
        $out = [];
        foreach ($u[1] as $ref) {
            if (! str_starts_with($ref, 'data:') && ! str_contains($ref, '//')) {
                $out[] = $this->resolve(dirname($rel), $ref);
            }
        }

        return $out;
    }

    private function resolve(string $dir, string $rel): string
    {
        $parts = [];
        foreach (explode('/', (str_starts_with($rel, '/') ? '' : trim($dir, '/.').'/').ltrim($rel, '/')) as $p) {
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

    /**
     * Download host-relative files into $root, skipping ones already there
     * (unless $refresh) — the shared store is content-addressed.
     *
     * @param  array<int, string>  $rels
     */
    private function fetch(array $rels, string $root, bool $required, bool $refresh = false, ?int &$absent = null): void
    {
        $absent = 0;
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
                $r = $responses[$rel] ?? null;
                if ($r instanceof Response && $r->successful() && ! str_contains((string) $r->header('Content-Type'), 'text/html')) {
                    File::ensureDirectoryExists(dirname("{$root}/{$rel}"));
                    // write-then-rename: a parallel import never reads half a shared file
                    $tmp = "{$root}/{$rel}.".getmypid().'.part';
                    File::put($tmp, $r->body());
                    rename($tmp, "{$root}/{$rel}");
                    $this->fetched++;
                } else {
                    $absent++;
                    if ($required) {
                        $this->missing[] = $rel;
                    }
                }
            }
            if ($this->progress) {
                ($this->progress)(sprintf('%d downloaded, %d already here', $this->fetched, $this->reused));
            }
        }
    }
}
