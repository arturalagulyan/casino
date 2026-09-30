<?php

namespace App\Services\GamePlay;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mirrors one Amatic "gmsl/mpp" game (the client generation AdmiralNelsonNew
 * uses, see GameAssetController::patchLegacyGmslQuirks) off Amatic's CDN into
 * a local directory, keeping the CDN's own path hierarchy (`gmsl/mpp/...`) so
 * every relative reference inside the client keeps resolving.
 *
 * The client never lists its files anywhere — they're discovered the same way
 * the browser would, by walking the chain:
 *
 *   gmsl/amanet/game.html?game=<key>      launcher; its data_*.json says which
 *                                         generation the game is (mpp/wcg/mphome)
 *   gmsl/mpp/amarent/<key>.html           the real page (+ css, css url()s)
 *   amarent/src/<key>loader_*.js          document.write()s the config + engine
 *   amarent/src/config_<id>_*.js          `Config` — socket endpoint, locales…
 *   <key>/src/<key>_*.js                  engine; names the game id + manifest version
 *   <key>/data/resources_<device>_<v>_<w>.json   every image/sound/locale it preloads
 *   atlas .json → meta.image, .fnt → page file, sounds → .ogg/.m4a/.mp3
 *
 * Images are fetched in both their manifest format and the `.webp` twin the
 * engine swaps in when the browser supports it. A missing manifest resource is
 * reported, not ignored: the client's preloaders abort the whole queue on the
 * first 404 (a missing locale hangs loading forever; a missing sound mutes the
 * game permanently via localStorage).
 */
class AmaticCdnFetcher
{
    private const int CONCURRENCY = 16;

    private const array SOUND_EXTENSIONS = ['ogg', 'm4a', 'mp3'];

    /** Swapped into Config.value6 (Amatic's own wss:// endpoint) — our game socket, via the same lookup the jackpot ticker uses. */
    private const string SOCKET_CONFIG_PREAMBLE = <<<'JS'
var serverString='';
var XmlHttpRequest = new XMLHttpRequest();
XmlHttpRequest.overrideMimeType("application/json");
XmlHttpRequest.open('GET', '/socket_config.json', false);
XmlHttpRequest.onreadystatechange = function ()
{
    if (XmlHttpRequest.readyState == 4 && XmlHttpRequest.status == "200")
    {
        var serverConfig = JSON.parse(XmlHttpRequest.responseText);
        serverString=serverConfig.prefix_ws+serverConfig.host_ws+':'+serverConfig.port;
    }
}
XmlHttpRequest.send(null);

JS;

    private string $origin = '';

    private string $outDir = '';

    /** @var array<string, bool> host-relative path => fetched ok */
    private array $seen = [];

    /** @var list<string> manifest/page resources that 404'd — the client will choke on these */
    private array $missing = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $bytes = 0;

    private bool $classic = false;

    /** @var (callable(string): void)|null */
    private $progress;

    /** @param (callable(string): void)|null $progress */
    public function fetch(string $gameUrl, string $outDir, ?callable $progress = null): AmaticCdnGame
    {
        $this->reset($outDir, $progress);

        $parts = parse_url($gameUrl);
        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new RuntimeException("Not a game URL: {$gameUrl}");
        }
        $this->origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        parse_str($parts['query'] ?? '', $query);
        $path = ltrim($parts['path'], '/');

        // Either the launcher URL (…/amanet/game.html?game=<key>) or the page it redirects to.
        if (preg_match('#^(.*)/mpp/amarent/([a-z0-9_]+)\.html$#i', $path, $m)) {
            [$root, $key] = [$m[1], strtolower($m[2])];
        } else {
            $key = strtolower((string) ($query['game'] ?? ''));
            if ($key === '') {
                throw new RuntimeException('The URL has no `game=` parameter.');
            }
            $root = trim(dirname($path, 2), './');
            $this->assertMppGeneration(dirname($path), $key);
        }
        // The launcher also appends `classic=true` for games in its `classic`
        // list — the variant whose rules match the legacy backend's maths.
        $urlParams = $this->classic || ($query['classic'] ?? null) === 'true' ? ['classic' => 'true'] : [];

        $mpp = ($root !== '' ? $root.'/' : '').'mpp';
        $pageDir = $mpp.'/amarent';
        $entry = "{$pageDir}/{$key}.html";
        $configId = preg_match('/^\d+$/', (string) ($query['config'] ?? '')) ? (string) $query['config'] : null;

        // 1. the page, its stylesheet(s) and loader script(s)
        $this->log("page {$entry}");
        $html = $this->get($entry, required: true) ?? throw new RuntimeException("Game page not found: {$this->origin}/{$entry} — is `{$key}` an mpp-generation Amatic game?");
        $pageRefs = $this->htmlRefs($html, $pageDir);
        $bodies = $this->getMany($pageRefs, required: true);

        foreach ($bodies as $rel => $body) {
            if ($body !== null && str_ends_with($rel, '.css')) {
                $this->getMany($this->cssRefs($body, dirname($rel)), required: false);
            }
        }

        // The in-game "rules" button opens ./gamerules.html in a new window.
        if (($rules = $this->get("{$pageDir}/gamerules.html", required: false)) !== null) {
            $this->getMany($this->htmlRefs($rules, $pageDir), required: false);
        }

        // 2. loader → config + engine scripts
        $engines = [];
        $configRel = null;
        foreach ($bodies as $rel => $body) {
            if ($body === null || ! str_ends_with($rel, '.js')) {
                continue;
            }
            preg_match_all('/\[\s*"[^"]*"\s*,\s*"([^"]+\.js)"/', $body, $scripts);
            foreach ($scripts[1] as $src) {
                $engines[] = $this->resolve($pageDir, $src);
            }
            if (preg_match('/"([^"]*config_)"\s*\+\s*getParam\("config"\)\s*\+\s*"([^"]+\.js)"/', $body, $c)) {
                if ($configId === null) {
                    throw new RuntimeException('The loader needs a `config=<id>` URL parameter (e.g. config=1861) — copy it from the original game URL.');
                }
                $configRel = $this->resolve($pageDir, $c[1].$configId.$c[2]);
            }
        }
        if ($engines === []) {
            throw new RuntimeException("No engine script found in {$entry}'s loader.");
        }

        if ($configRel !== null) {
            $this->log("config {$configRel}");
            $config = $this->get($configRel, required: true) ?? throw new RuntimeException("Config {$configRel} not found — wrong `config=` id?");
            $this->store($configRel, $this->pointConfigAtOurSocket($config));
        }

        // 3. engine → game identity + manifest version + resolutions
        $gameId = Str::studly($key);
        $title = Str::headline($key);
        $version = null;
        $widths = [];
        $literals = [];
        foreach (array_unique($engines) as $rel) {
            $this->log("engine {$rel}");
            $js = $this->get($rel, required: true);
            if ($js === null) {
                continue;
            }
            // e.g. this.kQv=this.k_G="AztecSecret";this.k3V="Aztec Secret";this.kdU="_10";
            //  or this.b31=this.bwH=this.bK7="Admiral";this.b68="_19";  (no title)
            // The version must be exact: the CDN keeps stale manifests
            // (Admiral still has _4) that no longer match the images/sounds.
            preg_match_all('/(?:this\.\w+=){2,}"([A-Za-z0-9]+)";(?:this\.\w+="([^"_][^"]*)";)?this\.\w+="(_\d+)?"/', $js, $ids, PREG_SET_ORDER);
            foreach ($ids as $id) {
                if (strcasecmp($id[1], $key) === 0) {
                    [$gameId, $title, $version] = [$id[1], ($id[2] ?? '') ?: $title, $id[3] ?? ''];

                    break;
                }
            }
            preg_match_all('#"/(?:core|slot|gamble)/images/(\d{3,4})_\d{3,4}/#', $js, $w);
            $widths = array_merge($widths, $w[1]);
            preg_match_all('#"(/(?:core|slot|gamble|'.preg_quote($key, '#').')/[A-Za-z0-9_./-]+\.(?:json|fnt|png|jpg|webp))"#', $js, $lit);
            $literals = array_merge($literals, $lit[1]);
        }
        $widths = array_values(array_unique($widths ?: ['1280', '960']));

        // 4. resource manifests (the engine picks device + resolution at runtime; take them all)
        $gameDir = $mpp.'/'.strtolower($gameId);
        $versions = $version !== null ? [$version] : ['', ...array_map(fn ($n) => "_{$n}", range(1, 40))];
        $manifests = [];
        foreach (['desktop', 'mobile'] as $device) {
            foreach ($widths as $w) {
                foreach ($versions as $v) {
                    $rel = "{$gameDir}/data/resources_{$device}{$v}_{$w}.json";
                    if (($body = $this->get($rel, required: false)) !== null) {
                        $manifests[$rel] = $body;
                        $version ??= $v;

                        break;
                    }
                }
            }
        }
        if ($manifests === []) {
            throw new RuntimeException("No resources_*.json manifest found under {$gameDir}/data.");
        }
        $this->log(count($manifests).' manifest(s): '.implode(', ', array_map('basename', array_keys($manifests))));

        // 5. every resource the manifests declare
        $languages = [];
        $plain = $sounds = [];
        foreach ($manifests as $body) {
            foreach ((array) data_get(json_decode($body, true), 'resources', []) as $res) {
                $url = (string) ($res['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $rel = $this->resolve($pageDir, '..'.(str_starts_with($url, '/') ? '' : '/').$url);
                match ((int) ($res['type'] ?? -1)) {
                    3, 4 => $sounds[$rel] = true,
                    default => $plain[$rel] = true,
                };
                if ((int) ($res['type'] ?? -1) === 0 && preg_match('/^[a-z]{2}$/', (string) ($res['id'] ?? ''))) {
                    $languages[] = $res['id'];
                }
            }
        }

        $this->log(count($plain).' files + '.count($sounds).' sounds declared');
        $this->expand($this->getMany(array_keys($plain), required: true));
        $this->fetchSounds(array_keys($sounds));

        // 6. extras the engine references directly (jackpot/promo overlays, …) — optional
        $extras = array_map(fn ($l) => $this->resolve($pageDir, '..'.$l), array_unique($literals));
        $this->expand($this->getMany($extras, required: false));

        $languages = array_values(array_unique($languages));
        sort($languages);

        return new AmaticCdnGame(
            key: $key,
            gameId: $gameId,
            title: $title,
            entry: $entry,
            configId: $configId,
            currency: isset($query['currency']) ? strtoupper((string) $query['currency']) : null,
            languages: $languages,
            urlParams: $urlParams,
            files: array_keys(array_filter($this->seen)),
            bytes: $this->bytes,
            missing: array_values(array_unique($this->missing)),
            warnings: $this->warnings,
        );
    }

    /**
     * The launcher only sends games listed in its data_*.json `games` (and not
     * in `wildcatgaming`) to mpp/amarent — the others are different client
     * generations this importer (and our Amatic protocol port) doesn't cover.
     */
    private function assertMppGeneration(string $launcherDir, string $key): void
    {
        $page = $this->request($launcherDir.'/game.html');
        $launcher = $page && preg_match('/src="([^"]+\.js)"/', $page, $m) ? $this->request($this->resolve($launcherDir, $m[1])) : null;
        $data = $launcher && preg_match('#"(\./data/data_[^"]+\.json)"#', $launcher, $d)
            ? json_decode((string) $this->request($this->resolve($launcherDir, $d[1])), true)
            : null;

        if (! is_array($data) || ! isset($data['games'])) {
            $this->warnings[] = "Couldn't read the launcher's game list — assuming `{$key}` is an mpp-generation game.";

            return;
        }

        if (! in_array($key, (array) $data['games'], true) || in_array($key, (array) ($data['wildcatgaming'] ?? []), true)) {
            throw new RuntimeException("`{$key}` isn't an mpp-generation Amatic game (the launcher routes it to mphome/wcg), which this importer doesn't support.");
        }

        $this->classic = in_array($key, (array) ($data['classic'] ?? []), true);
    }

    /** Point Config.value6 (Amatic's wss:// server) at our game socket. */
    private function pointConfigAtOurSocket(string $config): string
    {
        $patched = preg_replace('/(this\.value6\s*=\s*)"wss?:\/\/[^"]*"/', '$1serverString', $config, 1, $count);
        if (! $count) {
            $this->warnings[] = 'Config has no wss:// value6 socket URL — left unpatched.';

            return $config;
        }

        return self::SOCKET_CONFIG_PREAMBLE.$patched;
    }

    /**
     * Follow what fetched manifest entries point at: an atlas .json's
     * meta.image (+ multi-pack siblings), a bitmap font's page image, and the
     * .webp twin of every .png/.jpg.
     *
     * @param  array<string, string|null>  $bodies
     */
    private function expand(array $bodies): void
    {
        $next = [];
        foreach ($bodies as $rel => $body) {
            $dir = dirname($rel);
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

            if (in_array($ext, ['png', 'jpg'], true)) {
                $next[] = substr($rel, 0, -3).'webp';
            } elseif ($body !== null && $ext === 'json') {
                $json = json_decode($body, true);
                if ($image = data_get($json, 'meta.image')) {
                    $next[] = $this->resolve($dir, (string) $image);
                }
                foreach ((array) data_get($json, 'meta.related_multi_packs', []) as $pack) {
                    $next[] = $this->resolve($dir, (string) $pack);
                }
            } elseif ($body !== null && $ext === 'fnt') {
                preg_match_all('/<page\b[^>]*\bfile=[\'"]([^\'"]+)[\'"]/', $body, $pages);
                foreach ($pages[1] as $file) {
                    $next[] = $this->resolve($dir, pathinfo($file, PATHINFO_EXTENSION) ? $file : $file.'.png');
                }
            }
        }

        $next = array_values(array_diff(array_unique($next), array_keys($this->seen)));
        if ($next !== []) {
            $this->expand($this->getMany($next, required: false));
        }
    }

    /**
     * Manifest sounds are extension-less; the engine appends whichever of
     * .ogg/.m4a/.mp3 the browser can play. Mirror every variant that exists.
     *
     * @param  list<string>  $sounds
     */
    private function fetchSounds(array $sounds): void
    {
        $variants = [];
        foreach ($sounds as $rel) {
            foreach (self::SOUND_EXTENSIONS as $ext) {
                $variants[] = "{$rel}.{$ext}";
            }
        }
        $this->getMany($variants, required: false);

        foreach ($sounds as $rel) {
            $found = array_filter(self::SOUND_EXTENSIONS, fn ($ext) => $this->seen["{$rel}.{$ext}"] ?? false);
            if ($found === []) {
                $this->missing[] = "{$rel}.(ogg|m4a|mp3)";
            }
        }
    }

    // ---- HTML / CSS references -----------------------------------------

    /** @return list<string> */
    private function htmlRefs(string $html, string $dir): array
    {
        preg_match_all('/\b(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $m);

        return array_values(array_unique(array_map(
            fn ($ref) => $this->resolve($dir, $ref),
            array_filter($m[1], fn ($ref) => $this->isLocalRef($ref)),
        )));
    }

    /** @return list<string> */
    private function cssRefs(string $css, string $dir): array
    {
        preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css, $m);

        return array_values(array_unique(array_map(
            fn ($ref) => $this->resolve($dir, $ref),
            array_filter($m[1], fn ($ref) => $this->isLocalRef($ref)),
        )));
    }

    private function isLocalRef(string $ref): bool
    {
        return ! preg_match('#^(?:[a-z]+:|//|\#)#i', $ref);
    }

    /** Resolve `$ref` against host-relative directory `$dir` (handles ./, ../, leading /, ?query). */
    private function resolve(string $dir, string $ref): string
    {
        $ref = preg_replace('/[?#].*$/', '', $ref) ?? $ref;
        $path = str_starts_with($ref, '/') ? $ref : $dir.'/'.$ref;

        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $seg;
        }

        return implode('/', $out);
    }

    // ---- HTTP ----------------------------------------------------------

    private function get(string $rel, bool $required): ?string
    {
        return $this->getMany([$rel], $required)[$rel] ?? null;
    }

    /**
     * Fetch (and mirror to disk) each host-relative path not fetched yet.
     *
     * @param  list<string>  $rels
     * @return array<string, string|null> body per path, null when it 404'd
     */
    private function getMany(array $rels, bool $required): array
    {
        $out = [];
        $todo = [];
        foreach (array_unique($rels) as $rel) {
            if (array_key_exists($rel, $this->seen)) {
                $out[$rel] = $this->seen[$rel] ? (string) file_get_contents($this->outDir.'/'.$rel) : null;
            } else {
                $todo[] = $rel;
            }
        }

        foreach (array_chunk($todo, self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(60)->get($this->origin.'/'.$rel),
                $chunk,
            ));

            foreach ($chunk as $rel) {
                $response = $responses[$rel] ?? null;
                $ok = $response instanceof Response && $response->successful();
                $this->seen[$rel] = $ok;
                $out[$rel] = $ok ? $response->body() : null;

                if ($ok) {
                    $this->store($rel, $response->body());
                } elseif ($required) {
                    $this->missing[] = $rel;
                }
            }
        }

        return $out;
    }

    /** One-off GET that is not mirrored (the launcher isn't part of the bundle). */
    private function request(string $rel): ?string
    {
        try {
            $response = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($this->origin.'/'.$rel);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(string $rel, string $body): void
    {
        $target = $this->outDir.'/'.$rel;
        if (is_file($target)) {
            $this->bytes -= (int) filesize($target);
        }
        File::ensureDirectoryExists(dirname($target));
        file_put_contents($target, $body);
        $this->bytes += strlen($body);
    }

    private function log(string $message): void
    {
        if ($this->progress) {
            ($this->progress)($message);
        }
    }

    /** @param (callable(string): void)|null $progress */
    private function reset(string $outDir, ?callable $progress): void
    {
        $this->outDir = rtrim($outDir, '/');
        $this->progress = $progress;
        $this->seen = $this->missing = $this->warnings = [];
        $this->bytes = 0;
        $this->classic = false;
        File::deleteDirectory($this->outDir);
        File::ensureDirectoryExists($this->outDir);
    }
}
