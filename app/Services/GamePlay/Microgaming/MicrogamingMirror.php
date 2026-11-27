<?php

namespace App\Services\GamePlay\Microgaming;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Mirrors a Microgaming Titan game ("TheForce" client) off Betboy's static
 * host (`https://static.joker-entry.link/mg/`), keeping the host's hierarchy:
 *
 *   index.html                    the launch page (bundle entry) — static-host
 *                                 URLs as `{{MG_STATIC}}`, session-host URLs as
 *                                 `{{MG_SESSION}}` (filled in when served)
 *   commonStatic/<ver>/…          the Titan shell: plugins (js, css), strings
 *   <game>/gameassets-*.js        the game's asset list
 *   <game>/preloader/…            its preloader (config, script, css, images)
 *   <game>/scripts/<hash>.js      the game
 *   <game>/assets/config/<hash>.json   its scene config — names every atlas,
 *                                 image, sound and video ({path, defaultName,
 *                                 overrideOptions: {lang: …}})
 *   <game>/languages/<lang>/language-<hash>.json
 *
 * plus whatever those files name (CSS `url(…)`, quoted asset paths).
 */
class MicrogamingMirror
{
    public const string HOST = 'https://static.joker-entry.link/mg/';

    private const int CONCURRENCY = 16;

    private const string EXT = 'png|jpg|jpeg|webp|gif|svg|json|atlas|fnt|xml|mp3|ogg|m4a|mp4|webm|wav|woff2?|ttf|otf|css|js';

    /** Cocos Creator's texture extension ids. */
    private const array COCOS_EXT = ['.png', '.jpg', '.jpeg', '.bmp', '.webp', '.pvr', '.pkm'];

    private string $outDir = '';

    /** @var array<string, bool> host-relative path => fetched ok */
    private array $seen = [];

    /** @var list<string> */
    private array $missing = [];

    private int $reported = -1;

    /** @var (callable(string): void)|null */
    private $progress;

    /**
     * @param  string  $launch  Betboy's DEMO launch URL (the session host's page)
     * @return array{entry: string, folder: string, html: string, files: int, bytes: int, missing: list<string>, languages: list<string>, titan_config: string, session: array<string, array<string, mixed>>, errors: list<string>}
     */
    public function mirror(string $launch, string $outDir, ?callable $progress = null): array
    {
        $session = (string) parse_url($launch, PHP_URL_HOST);
        $this->outDir = rtrim($outDir, '/');
        $this->progress = $progress;
        $this->seen = $this->missing = [];
        File::deleteDirectory($this->outDir);
        File::ensureDirectoryExists($this->outDir);

        // 1. the game played in a browser: what it loads, what the session host answers
        $capture = $this->capture($launch);
        $html = $clientPacket = '';
        $replies = [];
        $static = [];
        foreach ($capture['requests'] as $r) {
            $url = (string) $r['url'];
            if (str_starts_with($url, self::HOST) && (int) $r['status'] === 200) {
                $static[] = rawurldecode((string) strtok(substr($url, strlen(self::HOST)), '?'));
            } elseif (parse_url($url, PHP_URL_HOST) === $session) {
                // the client's own first game packet: which packet format it speaks
                if ($clientPacket === '' && is_array($req = json_decode((string) ($r['request'] ?? ''), true)) && isset($req['packet']['payload'])) {
                    $clientPacket = (string) $req['packet']['payload'];
                }
                // the older mobile shell (Gods of Giza) posts bare XML packets to WebEngine.php?&a=<time>:
                // its AdvSlot one is the game's; the rest (GenericAuthLogin, MachineInfo, …) are kept by verb
                $raw = ltrim((string) ($r['request'] ?? ''));
                if (str_starts_with($raw, '<Pkt') && preg_match('/<Id\b[^>]*\bverb=[\'"]([A-Za-z_]+)[\'"]/', $raw, $vb)) {
                    if ($vb[1] === 'AdvSlot') {
                        $clientPacket = $clientPacket ?: $raw;
                    } elseif (! isset($replies["xml:{$vb[1]}"]) && (string) ($r['body'] ?? '') !== '') {
                        $replies["xml:{$vb[1]}"] = ['status' => (int) $r['status'], 'type' => (string) $r['type'], 'body' => $this->localise((string) $r['body'], $session), 'location' => ''];
                    }

                    continue;
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path === '/' && $r['method'] === 'GET') {
                    $html = $html ?: (string) ($r['body'] ?? '');

                    continue;
                }
                $key = self::replyKey($path, (string) parse_url($url, PHP_URL_QUERY));
                if ($key !== null && ! isset($replies[$key]) && ((string) ($r['body'] ?? '') !== '' || (string) $r['location'] !== '')) {
                    $replies[$key] = ['status' => (int) $r['status'], 'type' => (string) $r['type'],
                        'body' => $this->localise((string) ($r['body'] ?? ''), $session), 'location' => $this->localise((string) $r['location'], $session)];
                }
            }
        }
        if ($html === '') {
            $html = Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 1000)->get($launch)->body();
        }
        $info = MicrogamingLiveClient::startupInfo($html);
        $folder = trim(str_replace(self::HOST, '', (string) $info['gameUrl']), '/');
        if (! preg_match('/^[a-z0-9_]+$/i', $folder)) {
            throw new RuntimeException("Not a Microgaming game folder: {$info['gameUrl']}");
        }

        // 2. the launch page, and every static file the game loaded
        $page = $this->localise($html, $session);
        File::put("{$this->outDir}/index.html", $page);
        preg_match_all('#\{\{MG_STATIC\}\}([^"\'\s<>()]+\.(?:'.self::EXT.'))#i', $page, $m);
        $this->getMany(array_values(array_unique(array_merge($m[1], $static))), false);
        $config = (string) ($replies['mg/config']['body'] ?? '');

        // 3. the game folder: the files the page's preloader asks for
        $hash = fn (string $k) => preg_match('/"'.$k.'":"([0-9a-f]+)"/', $html, $h) ? $h[1] : null;
        $wanted = ["{$folder}/preloader/preloaderConfig.json", "{$folder}/preloader/preloader.js"];
        if ($js = $hash('gameJSHash')) {
            $wanted[] = "{$folder}/scripts/{$js}.js";
        }
        if ($json = $hash('gameJsonHash')) {
            $wanted[] = "{$folder}/assets/config/{$json}.json";
        }
        // older pages name them in script variables instead (Agent Jane Blonde:
        // gameConfigFilePath="assets/config/<hash>.json", gameJsFilePath="scripts/<hash>.js")
        $named = fn (string $var) => preg_match('/\b'.$var.'="\/?([^"]+)"/', $html, $v) ? $v[1] : null;
        if (($cfg = $named('gameConfigFilePath')) && preg_match('#assets/config/([0-9a-f]+)\.json$#', $cfg, $c)) {
            $json ??= $c[1];
            $wanted[] = "{$folder}/{$cfg}";
        }
        if ($gameJs = $named('gameJsFilePath')) {
            $wanted[] = "{$folder}/{$gameJs}";
        }
        // the preloader's own stylesheet (PRELOADER_STYLESHEET="preloader-<hash>.css", under preloader/game/)
        if (preg_match('/PRELOADER_STYLESHEET="([^"\/]+\.css)"/', $html.$this->readAll($folder, 'preloader'), $css)) {
            $wanted[] = "{$folder}/preloader/game/{$css[1]}";
        }
        $this->getMany($wanted, false);
        preg_match_all('#gameUrl\+"(preloader/[^"]+)"#', $html, $p);
        $this->getMany(array_map(fn ($f) => "{$folder}/{$f}", $p[1]), false);
        preg_match_all('#path:"([^"]+)"#', $this->readAll($folder, 'gameassets'), $ga);
        $this->getMany(array_map(fn ($f) => $this->resolve($folder, $f), $ga[1]), false);

        // its preloader config: preloaderConfig.json, or hashed (preloaderConfig-<hash>.json, named in the page)
        $preConfig = "{$folder}/preloader/preloaderConfig.json";
        if ($this->read($preConfig) === '' && preg_match('#preloader/(preloaderConfig-[0-9a-f]+\.json)#', $html, $pc)) {
            $preConfig = "{$folder}/preloader/{$pc[1]}";
            $this->getMany([$preConfig], false);
        }
        $pre = (array) json_decode($this->read($preConfig), true);
        $languages = array_values(array_map('strval', (array) ($pre['language']['supported'] ?? $pre['supportedLanguages'] ?? ['en'])));
        $resolutions = array_values(array_map('strval', (array) ($pre['resolution']['supported'] ?? $pre['supportedResolutions'] ?? [])));
        if ($lang = $hash('gameLanguageJsonHash')) {
            $this->getMany(array_map(fn ($l) => "{$folder}/languages/{$l}/language-{$lang}.json", $languages), false);
        }
        // preloader images: "preloader/game/%deviceRes%/%lang%/x.png"
        preg_match_all('#"((?:preloader/)?[^"]*\.(?:png|jpg|jpeg|webp))"#', $this->read($preConfig), $img);
        $pics = [];
        foreach (array_unique($img[1]) as $path) {
            foreach ($resolutions ?: [''] as $res) {
                foreach (str_contains($path, '%lang%') ? $languages : [''] as $l) {
                    $pics[] = $this->resolve($folder, str_replace(['%deviceRes%', '%lang%'], [$res, $l], $path));
                    if (str_ends_with($path, '.png') || str_ends_with($path, '.jpg')) {
                        $pics[] = $this->resolve($folder, (string) preg_replace('/\.(png|jpe?g)$/', '.webp', str_replace(['%deviceRes%', '%lang%'], [$res, $l], $path)));
                    }
                }
            }
        }
        $this->getMany($pics, false);

        // the shell's own: its strings per language (componentStoreLanguage), the publisher logo
        $this->getMany(array_merge(array_map(fn ($l) => "commonStatic/MobileLanguages/{$l}.json", $languages), ['commonStatic/logo.png']), false);

        // 4. what the scene config names
        if ($json) {
            // localised files name their language folder `languageCode` (assets/res/audio/languageCode/x.mp3)
            $assets = [];
            foreach ($this->configAssets($folder, (array) json_decode($this->read("{$folder}/assets/config/{$json}.json"), true)) as $a) {
                array_push($assets, ...(str_contains($a, '/languageCode/') ? array_map(fn ($l) => str_replace('/languageCode/', "/{$l}/", $a), $languages) : [$a]));
            }
            $this->getMany($assets, false);
        }

        // the loader asks for x.webp in place of x.png where the browser has WebP
        $this->getMany(array_map(fn ($r) => substr($r, 0, -4).'.webp',
            array_values(array_filter(array_keys(array_filter($this->seen)), fn ($r) => str_ends_with($r, '.png') && str_starts_with($r, "{$folder}/")))), false);

        // 5. what every fetched text file names: CSS url(…), quoted asset paths
        $queue = array_keys(array_filter($this->seen));
        for ($round = 0; $round < 4 && $queue !== []; $round++) {
            $next = [];
            foreach ($queue as $rel) {
                foreach ($this->fileRefs($rel, $folder) as $cand) {
                    if (! isset($this->seen[$cand])) {
                        $next[] = $cand;
                    }
                }
            }
            $before = array_keys(array_filter($this->seen));
            $this->getMany(array_values(array_unique($next)), false);
            $queue = array_values(array_diff(array_keys(array_filter($this->seen)), $before));
        }

        // 6. Cocos Creator bundles load some packs, and the images of their
        // textures, only when a scene opens (Book of Mega Moolah's free spins):
        // fetch every pack a bundle lists, then every texture's image
        $this->getMany($this->cocosPacks($folder), false);
        $this->getMany($this->cocosNatives($folder), false);

        $files = File::allFiles($this->outDir, true);

        return [
            'entry' => 'index.html',
            'folder' => $folder,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => array_values(array_unique($this->missing)),
            'languages' => $languages,
            'titan_config' => $config,
            'html' => $html,
            'session' => $replies,
            'errors' => $capture['errors'],
            'client_packet' => $clientPacket,
        ];
    }

    /** Static-host URLs → {{MG_STATIC}}, session-host URLs → {{MG_SESSION}} (config.php → config.js, WebEngine.php → engine). */
    public function localise(string $text, string $session): string
    {
        $text = str_replace([self::HOST, str_replace('https:', 'http:', self::HOST)], '{{MG_STATIC}}', $text);
        $text = str_replace(["https://{$session}", "http://{$session}", 'https://www.'.$session, 'http://www.'.$session], '{{MG_SESSION}}', $text);

        // the game folder's root ("GamesPath":"/mg/" — the host's root, not ours)
        $text = str_replace('"GamesPath":"/mg/"', '"GamesPath":"{{MG_STATIC}}"', $text);

        $text = str_replace(['{{MG_SESSION}}/mg/config.php', '{{MG_SESSION}}/skvCore/WebEngine.php'], ['{{MG_SESSION}}/config.js', '{{MG_SESSION}}/engine'], $text);

        // other session-host pages lose `.php` (nginx hands any .php path to fastcgi): served from the recorded replies
        return (string) preg_replace('#(\{\{MG_SESSION\}\}/[^"\'\s?]*?)\.php#', '$1', $text);
    }

    /**
     * Where a session-host reply is filed: `engine:<path>` for the API
     * (WebEngine.php?<n><path>?… — a request counter may lead the path),
     * else the path without `.php` (`mg/commonStatic/clients/`).
     */
    public static function replyKey(string $path, string $query): ?string
    {
        $path = ltrim($path, '/');
        if (str_ends_with($path, 'WebEngine.php') || $path === 'engine') {
            $sub = (string) strtok(rawurldecode($query), '?&');
            $sub = (string) preg_replace('#^\d*#', '', $sub);

            return str_starts_with($sub, 'a=') || $sub === '' ? null : 'engine:'.'/'.ltrim($sub, '/');
        }

        return rtrim((string) preg_replace('#\.php$#', '', $path), '/');
    }

    /** @return array{requests: list<array<string, mixed>>, errors: list<string>} */
    private function capture(string $launch): array
    {
        $out = storage_path('app/tmp/microgaming-capture-'.getmypid().'.json');
        File::ensureDirectoryExists(dirname($out));
        $result = Process::timeout(600)   // a heavy client (Jungle Jim El Dorado) takes ~5 minutes in software WebGL
            ->env(['MICROGAMING_PLAYWRIGHT' => (string) config('services.microgaming.playwright', '/opt/pw/node_modules')])
            ->run(['node', resource_path('microgaming/capture.cjs'), $launch, $out]);
        $data = is_file($out) ? json_decode((string) file_get_contents($out), true) : null;
        File::delete($out);
        if (! is_array($data)) {
            throw new RuntimeException('The browser capture failed: '.mb_strimwidth($result->errorOutput() ?: $result->output(), 0, 300));
        }

        return ['requests' => (array) ($data['requests'] ?? []), 'errors' => array_values((array) ($data['errors'] ?? []))];
    }

    /**
     * Files the scene config names: `{path: "assets/res/atlas/", defaultName:
     * "@2x/<hash>@2x.json", overrideOptions: {lang: {…}}}` objects (every name
     * under the path), and bare "assets/…" strings — `{pixelRatio}` as @2x and @1x.
     *
     * @param  array<mixed>  $node
     * @return list<string>
     */
    private function configAssets(string $folder, array $node): array
    {
        $out = [];
        $walk = function ($node) use (&$walk, &$out, $folder) {
            if (! is_array($node)) {
                if (is_string($node) && preg_match('#^assets/.+\.(?:'.self::EXT.')$#i', $node)) {
                    $out[] = $node;
                }

                return;
            }
            if (isset($node['path']) && is_string($node['path']) && str_ends_with($node['path'], '/')) {
                array_walk_recursive($node, function ($v, $k) use (&$out, $node) {
                    if (is_string($v) && $k !== 'path' && preg_match('#^[^/\s]*/?[^\s]+\.(?:'.self::EXT.')$#i', $v) && ! str_starts_with($v, 'assets/')) {
                        $out[] = $node['path'].$v;
                    }
                });
            }
            foreach ($node as $v) {
                $walk($v);
            }
        };
        $walk($node);
        $paths = [];
        foreach (array_unique($out) as $p) {
            foreach (str_contains($p, '{pixelRatio}') ? ['@2x', '@1x'] : [null] as $ratio) {
                $paths[] = "{$folder}/".($ratio ? str_replace('{pixelRatio}', $ratio, $p) : $p);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Every pack (`import/<id[0,2]>/<id>.json`) the fetched Cocos Creator bundle
     * configs (`assets/<bundle>/config.json`) list.
     *
     * @return list<string>
     */
    private function cocosPacks(string $folder): array
    {
        $out = [];
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (! preg_match('#^'.preg_quote($folder, '#').'/assets/[^/]+/config\.json$#', $rel)) {
                continue;
            }
            $config = json_decode($this->read($rel), true);
            foreach (array_keys((array) ($config['packs'] ?? [])) as $pack) {
                $out[] = dirname($rel).'/import/'.substr((string) $pack, 0, 2)."/{$pack}.json";
            }
        }

        return $out;
    }

    /**
     * The image (`native/<uuid[0,2]>/<uuid><ext>`) of every texture in the
     * fetched texture packs (`{"type":"cc.Texture2D","data":"<ext>,…|…"}`, one
     * entry per pack member). The Languages bundle is left out: its other
     * languages' textures are never shown, and the capture loaded the played one's.
     *
     * @return list<string>
     */
    private function cocosNatives(string $folder): array
    {
        $out = [];
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (! preg_match('#^'.preg_quote($folder, '#').'/assets/([^/]+)/config\.json$#', $rel, $b) || strcasecmp($b[1], 'Languages') === 0) {
                continue;
            }
            $config = (array) json_decode($this->read($rel), true);
            $uuids = (array) ($config['uuids'] ?? []);
            foreach ((array) ($config['packs'] ?? []) as $pack => $members) {
                $data = json_decode($this->read(dirname($rel).'/import/'.substr((string) $pack, 0, 2)."/{$pack}.json"), true);
                foreach (self::cocosTextures($data) as $i => $entry) {
                    $member = $members[$i] ?? null;
                    $uuid = self::cocosUuid((string) (is_int($member) ? ($uuids[$member] ?? '') : $member));
                    $ext = self::COCOS_EXT[(int) strtok($entry, ',_@')] ?? null;
                    if ($uuid !== '' && $ext !== null) {
                        $out[] = dirname($rel).'/native/'.substr($uuid, 0, 2)."/{$uuid}{$ext}";
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A pack's textures: member index => its serialised texture ("<ext id>,<filters>…").
     * A texture-only pack is `{"type":"cc.Texture2D","data":"…|…"}`; a mixed one is
     * `[version, uuids, strings, classes, masks, [member: [[data…], [class index], …]]]`.
     *
     * @return array<int, string>
     */
    private static function cocosTextures(mixed $pack): array
    {
        if (is_array($pack) && ($pack['type'] ?? null) === 'cc.Texture2D' && is_string($pack['data'] ?? null)) {
            return explode('|', $pack['data']);
        }
        if (! is_array($pack) || ! array_is_list($pack) || ! is_array($pack[3] ?? null) || ! is_array($pack[5] ?? null)) {
            return [];
        }
        $classes = array_map(fn ($c) => is_array($c) ? ($c[0] ?? null) : $c, $pack[3]);
        $out = [];
        foreach ($pack[5] as $i => $member) {
            $class = $member[1][0] ?? null;
            $data = $member[0][0] ?? null;
            if (is_int($class) && ($classes[$class] ?? null) === 'cc.Texture2D' && is_string($data)) {
                $out[$i] = $data;
            }
        }

        return $out;
    }

    /** A config's compressed uuid (22 chars, base64) → the 36-char form files are named by. */
    public static function cocosUuid(string $short): string
    {
        if (strlen($short) !== 22) {
            return $short;
        }
        $b64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $hex = substr($short, 0, 2);
        for ($i = 2; $i < 22; $i += 2) {
            $a = strpos($b64, $short[$i]);
            $c = strpos($b64, $short[$i + 1]);
            if ($a === false || $c === false) {
                return $short;
            }
            $hex .= sprintf('%x%x%x', $a >> 2, (($a & 3) << 2) | ($c >> 4), $c & 15);
        }

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** @return list<string> host-relative paths a fetched file names */
    private function fileRefs(string $rel, string $folder): array
    {
        if (! preg_match('/\.(css|js|json)$/i', $rel) || str_contains($rel, '/language-')) {
            return [];
        }
        $text = $this->read($rel);
        $dir = dirname($rel);
        $refs = [];
        if (str_ends_with($rel, '.css')) {
            preg_match_all('#url\(\s*["\']?([^"\')?\#]+)#i', $text, $m);
            foreach ($m[1] as $u) {
                if (! str_starts_with($u, 'data:') && ! str_contains($u, '//')) {
                    $refs[] = $this->resolve($dir, $u);
                }
            }

            return $refs;
        }
        // quoted relative asset paths: against the file's folder and the game folder
        preg_match_all('#["\']((?:\./)?[A-Za-z0-9_@-][A-Za-z0-9_@./-]*/[A-Za-z0-9_@.-]+\.(?:png|jpg|jpeg|webp|gif|svg|json|atlas|fnt|mp3|ogg|m4a|mp4|webm|woff2?|ttf|otf|css))["\']#i', $text, $m);
        foreach (array_unique($m[1]) as $p) {
            if (str_contains($p, '..') || str_contains($p, '{') || str_contains($p, '%')) {
                continue;
            }
            $refs[] = str_starts_with($rel, 'commonStatic/') ? $this->resolve($dir, $p) : $this->resolve($folder, $p);
        }

        return array_values(array_unique($refs));
    }

    private function readAll(string $folder, string $prefix): string
    {
        $text = '';
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (str_starts_with($rel, "{$folder}/{$prefix}")) {
                $text .= $this->read($rel);
            }
        }

        return $text;
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

    /** @param list<string> $rels */
    private function getMany(array $rels, bool $required): void
    {
        $rels = array_values(array_filter(array_unique($rels), fn ($r) => $r !== '' && ! isset($this->seen[$r])));
        foreach (array_chunk($rels, self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(120)->retry(2, 500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
                    ->get(self::HOST.str_replace(' ', '%20', $rel)),
                $chunk,
            ));
            foreach ($chunk as $rel) {
                $r = $responses[$rel] ?? null;
                $ok = $r instanceof Response && $r->successful() && ! (str_contains((string) $r->header('Content-Type'), 'text/html') && ! str_ends_with($rel, '.html'));
                $this->seen[$rel] = $ok;
                if ($ok) {
                    $body = $r->body();
                    if (preg_match('/\.(css|js|json|html)$/i', $rel)) {
                        $body = str_replace(self::HOST, '{{MG_STATIC}}', $body);
                    }
                    File::ensureDirectoryExists(dirname("{$this->outDir}/{$rel}"));
                    File::put("{$this->outDir}/{$rel}", $body);
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
}
