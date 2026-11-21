<?php

namespace App\Services\GamePlay\Booongo;

use App\Services\GamePlay\Amatic\AmaticPageRecorder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a Booongo HTML5 game off its static host
 * (`https://static.joker-entry.link/bng/`), keeping the host's hierarchy:
 *
 *   index.html                            the page (bundle entry), written here:
 *                                         Betboy's launch page with its URLs made
 *                                         relative and the session parts read from
 *                                         `window.BNG` (server, token, language)
 *   commonStatic/gamerunner/<v>/…         Booongo's game runner (UI chrome, protocol)
 *   <game>/init.js, src/…, assets/…       the game
 *
 * Files come from a browser recording of a live demo ({@see AmaticPageRecorder})
 * plus the game's own asset manifest — the older engine's `url:"./assets/…"`
 * in its scripts, the newer engine's `json/resourceMap.json` (everything under
 * `resources/`) and `json/filelist.json` (string tables under `local/`) —
 * every resolution, sound and language, loaded or not, and whatever the
 * stylesheets reference.
 */
class BooongoMirror
{
    public const string HOST = 'https://static.joker-entry.link/bng/';

    private const int CONCURRENCY = 16;

    public function __construct(private readonly AmaticPageRecorder $recorder) {}

    /**
     * @param  callable(): string  $launch  a fresh Betboy launch URL (its page starts the runner) — asked for once
     *                                      this import holds the browser, so a wait can't stale it
     * @return array{entry: string, folder: string, runner: string, files: int, bytes: int, missing: list<string>, languages: list<string>}
     */
    public function mirror(callable $launch, string $outDir, ?callable $progress = null): array
    {
        // one browser at a time: the Selenium container serves a single session (parallel imports queue here)
        $progress && $progress('waiting for the browser…');
        $lock = fopen(storage_path('app/game-fetch/.booongo-recorder.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $launch = $launch();
            $recorded = $this->recorder->record($launch);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $html = Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 2000)->get($launch)->body();
        $page = self::parseLaunch($html);
        $folder = $page['folder'];
        $runner = $page['runner'];
        File::deleteDirectory($outDir);
        File::ensureDirectoryExists($outDir);

        // 1. what a live page loads
        $wanted = ["{$folder}/init.js", "{$runner}index.js", "{$runner}gr.js"];
        foreach ($recorded as $url) {
            if (str_starts_with($url, self::HOST) && ($rel = $this->rel($url)) !== null) {
                $wanted[] = $rel;
            }
        }
        $wanted = $this->keep($wanted, $folder, $runner);
        if (count(array_filter($wanted, fn ($p) => str_starts_with($p, "{$folder}/"))) < 20) {
            throw new RuntimeException("The browser recording loaded almost nothing of {$folder} — did the page start?");
        }
        $missing = $this->fetch($wanted, $outDir, $progress);

        // 2. the game's own manifest: every asset it can ask for, and its languages
        $extra = [];
        $languages = ['en'];
        foreach (File::allFiles("{$outDir}/{$folder}") as $f) {
            if ($f->getExtension() !== 'js') {
                continue;
            }
            $js = (string) File::get($f->getPathname());
            preg_match_all('#\burl\s*:\s*"\./(assets/[^"?]+)"#', $js, $m);
            foreach ($m[1] as $a) {
                $extra[] = "{$folder}/{$a}";
            }
            // module.exports=["de","en",…] — the languages it ships
            if (preg_match('#module\.exports=(\["[a-z]{2}(?:-[a-z]+)?"(?:,"[a-z]{2}(?:-[a-z]+)?")+\])#', $js, $l)) {
                $languages = array_values(array_unique(array_merge($languages, (array) json_decode($l[1], true))));
            }
        }
        // the newer engine (resources/, local/, json/): its manifests list everything
        $json = fn (string $name) => is_file($f = "{$outDir}/{$folder}/json/{$name}.json") ? (array) json_decode((string) File::get($f), true) : [];
        $map = $json('resourceMap');
        foreach (array_keys($map) as $rel) {
            $extra[] = "{$folder}/resources/{$rel}";
        }
        if ($map !== []) {
            $languages = array_values(array_unique(array_merge($languages, (array) ($json('languages')['list'] ?? []))));
        }
        $locals = [];
        foreach ($json('filelist') as $group) {
            foreach ((array) $group as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'local' && isset($item['name'])) {
                    $locals[] = (string) $item['name'];
                } elseif (is_string($item) && str_starts_with($item, 'css/')) {
                    $extra[] = "{$folder}/{$item}";
                }
            }
        }
        foreach ($languages as $lang) {
            $extra[] = "{$runner}i18n/{$lang}.json";
            if ($map === []) {
                $extra[] = "{$folder}/assets/i18n/{$lang}.json";
            }
            foreach (array_unique($locals) as $local) {
                $extra[] = "{$folder}/local/{$local}/{$lang}.json";
            }
        }
        $missing = array_merge($missing, $this->fetch(array_values(array_diff($this->keep($extra, $folder, $runner), $wanted)), $outDir, $progress, quiet: true));

        // 3. what the stylesheets reference (runner fonts, icons)
        $css = [];
        foreach (File::allFiles($outDir) as $f) {
            if ($f->getExtension() !== 'css') {
                continue;
            }
            $dir = dirname(str_replace('\\', '/', $f->getRelativePathname()));
            preg_match_all('#url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#', (string) File::get($f->getPathname()), $m);
            foreach ($m[1] as $u) {
                if (! preg_match('#^(data:|https?:|//|\#)#', $u)) {
                    $css[] = self::normalize($dir.'/'.preg_replace('/[?#].*$/', '', $u));
                }
            }
        }
        $this->fetch(array_values(array_filter(array_unique($css), fn ($p) => ! is_file("{$outDir}/{$p}"))), $outDir, $progress, quiet: true);

        File::put("{$outDir}/index.html", $this->entry($page));
        $files = File::allFiles($outDir, true);

        return [
            'entry' => 'index.html',
            'folder' => $folder,
            'runner' => $runner,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => array_values(array_unique($missing)),
            'languages' => $languages,
        ];
    }

    /**
     * The parts of Betboy's launch page the entry page is built from.
     *
     * @return array{folder: string, runner: string, runner_rev: string, ts: string, client_rev: string, options: array<string, mixed>, title: string, gif: string}
     */
    public static function parseLaunch(string $html): array
    {
        $grab = fn (string $re) => preg_match($re, $html, $m) ? $m[1] : '';
        $gc = $grab("#gcURL = '([^']+)'#");
        $static = $grab("#staticPath = '([^']+)'#");
        $options = json_decode($grab('#options:\s*(\{.*?\}),\s*\n#s'), true);
        if (! str_starts_with($gc, self::HOST) || ! str_starts_with($static, self::HOST) || ! is_array($options)) {
            throw new RuntimeException('Not a Booongo launch page (no gcURL / staticPath / options)');
        }

        return [
            'folder' => trim(substr($gc, strlen(self::HOST)), '/'),
            'runner' => rtrim(substr($static, strlen(self::HOST)), '/').'/',
            'runner_rev' => $grab("#revision: '([^']+)'\s*\},\s*options#") ?: $grab('#index\.js\?_ts=([a-z0-9]+)#'),
            'ts' => $grab('#index\.js\?_ts=([a-z0-9]+)#'),
            'client_rev' => $grab("#client_url: gcURL,\s*revision: '([^']+)'#"),
            'options' => $options,
            'title' => html_entity_decode($grab('#<title>([^<]*)</title>#')),
            'gif' => $grab('#window\.LOADER_GIF = "([^"]*)"#'),
        ];
    }

    /**
     * The bundle's page: the launch page's runner bootstrap with every URL
     * relative to the page (the bundle root) and the session parts —
     * `server` (our engine), `token`, `lang` — from `window.BNG`, which our
     * game launch injects.
     *
     * @param  array<string, mixed>  $page
     */
    private function entry(array $page): string
    {
        $options = array_merge($page['options'], ['token' => '', 'exit_url' => '', 'history_url' => '', 'cashier_url' => '',
            'replay_url' => '', 'queue' => '', 'wl' => 'demo', 'mobile' => '0']);   // wl demo: no analytics (Google Tag Manager)
        $json = fn ($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
    <title>'.htmlspecialchars($page['title']).'</title>
    <style>html,body{margin:0;padding:0;width:100%;height:100%;background:#000;overflow:hidden}</style>
</head>
<body>
<script>
window.LOADER_GIF = '.$json($page['gif']).';
(function () {
    var base = document.baseURI.replace(/[?#].*$/, "").replace(/[^\/]*$/, ""),
        s = window.BNG || {},
        options = '.$json($options).',
        runner = base + '.$json($page['runner']).',
        endpoints = {server_url: s.server || "", log_url: s.server || "", client_url: base + '.$json($page['folder'].'/').', revision: '.$json($page['client_rev']).'};
    options.token = s.token || "";
    options.lang = s.lang || options.lang || "en";
    window._PROVIDER = {gr: {static_path: runner, revision: '.$json($page['runner_rev']).'}, options: options, desktop: endpoints, mobile: endpoints};
    var v = document.createElement("script");
    v.setAttribute("type", "text/javascript");
    v.setAttribute("src", runner + "index.js?_ts=" + '.$json($page['ts']).');
    document.getElementsByTagName("head")[0].appendChild(v);
})();
</script>
</body>
</html>
';
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

    /**
     * @param  list<string>  $rels
     * @return list<string> the game's and the runner's files, once each
     */
    private function keep(array $rels, string $folder, string $runner): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $rels),
            fn (string $p) => str_starts_with($p, "{$folder}/") || str_starts_with($p, $runner))));
    }

    private function rel(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_starts_with($path, '/bng/') ? rawurldecode(substr($path, strlen('/bng/'))) : null;
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
