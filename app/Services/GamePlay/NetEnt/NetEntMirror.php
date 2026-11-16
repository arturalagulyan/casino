<?php

namespace App\Services\GamePlay\NetEnt;

use App\Enums\Currency;
use App\Services\GamePlay\Amatic\AmaticPageRecorder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a NetEnt HTML5 client off its static host, keeping the host's own
 * hierarchy (`nth/happy_riches/game/index.html`, `nth/currencies/…`), since
 * the client addresses its files relative to its page:
 *
 *   game/index.html, javascript/init.js     page + loader
 *   game/resources.xml                      every config the loader reads —
 *       languageXML  → ../langlib/<lang>/language.xml
 *       moneyformat  → ../../currencies/<iso>/moneyformat.xml (iso from our init)
 *       deviceDetection → <staticsharedurl>/… (gameclient_html/devicedetection/current)
 *   config/*.json                           images (inline base64), audio clip
 *                                           lists, loader images
 *   multimedia/audio/webAudio/<group>/<fmt>/<clip>.<fmt>   built in code from the clip lists
 *
 * A browser recording (desktop + mobile, the Selenium container) adds
 * whatever the code loads by other names.
 */
class NetEntMirror
{
    private const int CONCURRENCY = 16;

    /** Languages tried for langlib/<lang>/ — a game ships some of them. */
    private const array LANGUAGES = ['en', 'de', 'es', 'fr', 'it', 'ru', 'tr', 'pt', 'br', 'pl', 'nl', 'sv', 'fi', 'no', 'da',
        'ka', 'ro', 'hu', 'cs', 'el', 'bg', 'uk', 'zh', 'ja', 'ko', 'et', 'lv', 'lt', 'sk', 'sl', 'hr', 'sr', 'th', 'vi', 'mk', 'sq'];

    private const array AUDIO_FORMATS = ['ogg', 'mp3', 'm4a'];

    private string $origin = '';

    private string $outDir = '';

    /** @var array<string, bool> host-relative path => fetched ok */
    private array $seen = [];

    /** @var list<string> */
    private array $missing = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var (callable(string): void)|null */
    private $progress;

    public function __construct(private readonly AmaticPageRecorder $recorder) {}

    /**
     * @param  string  $page  the client page (…/<root>/<game>/game/index.html?…, with a live demo session for the recording)
     * @return array{entry: string, game_dir: string, root: string, languages: list<string>, files: int, bytes: int, missing: list<string>, warnings: list<string>}
     */
    public function mirror(string $page, string $outDir, ?callable $progress = null, bool $record = true): array
    {
        $this->outDir = rtrim($outDir, '/');
        $this->progress = $progress;
        $this->seen = $this->missing = $this->warnings = [];
        File::deleteDirectory($this->outDir);
        File::ensureDirectoryExists($this->outDir);

        $parts = parse_url($page);
        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        if (! isset($parts['scheme'], $parts['host']) || ! preg_match('#^(.+)/([^/]+)/game/index\.html$#', $path, $m)) {
            throw new RuntimeException("Not a NetEnt client page (…/<game>/game/index.html): {$page}");
        }
        $this->origin = $parts['scheme'].'://'.$parts['host'];
        [$root, $gameDir] = [$m[1], $m[1].'/'.$m[2]];
        $game = "{$gameDir}/game";

        // 1. page, its scripts/styles, the build manifest
        $entry = "{$game}/index.html";
        $html = $this->get($entry, true) ?? throw new RuntimeException("Game page not found: {$this->origin}/{$entry}");
        // operator additions loaded off the site root (`/sentry/loader.js`) — not part of the game
        $stripped = preg_replace('#<script\b[^>]*\bsrc\s*=\s*["\']/(?!/)[^"\']*["\'][^>]*>\s*</script>#i', '', $html, -1, $n);
        if ($n && is_string($stripped)) {
            $this->store($entry, $stripped);
            $this->warnings[] = "Dropped {$n} operator site-root script(s) from the page.";
        }
        $this->getMany($this->refs($html, $game), true);
        $this->getMany(["{$gameDir}/META-INF/MANIFEST.MF"], false);

        // 2. resources.xml — every config the loader reads. Single-bundle clients
        // (index.html → init.js, e.g. Busters Bones) have none: what they load
        // comes from the browser recording (3.) alone
        $xml = $this->get("{$game}/resources.xml", false);
        if ($xml === null) {
            if (! $record) {
                throw new RuntimeException('The client has no resources.xml, and without a browser recording nothing says what it loads');
            }
            $this->warnings[] = 'No resources.xml (single-bundle client) — the file list comes from the browser recording.';
            $xml = '';
        }
        preg_match_all('#<resource>(.*?)</resource>#s', $xml, $res);
        $wanted = [];
        $languages = [];
        foreach ($res[1] as $r) {
            $url = trim((string) (preg_match('#<url>([^<]+)</url>#', $r, $u) ? $u[1] : ''));
            $name = trim((string) (preg_match('#<name>([^<]+)</name>#', $r, $nm) ? $nm[1] : ''));
            if ($url === '') {
                continue;
            }
            if (in_array($name, ['languageXML', 'language', 'languageJSON'], true)) {   // `language`: the oldest touch clients
                foreach (self::LANGUAGES as $lang) {
                    $rel = $name !== 'languageJSON' ? "{$gameDir}/langlib/{$lang}/{$url}" : $this->resolve($game, $url.$lang.'.json');
                    if ($this->get($rel, false) !== null) {
                        $languages[] = $lang;
                    }
                }
            } elseif (str_starts_with($name, 'moneyformat')) {
                foreach ([...array_map(fn (Currency $c) => strtolower($c->value), Currency::cases()), 'nan'] as $iso) {
                    $wanted[] = "{$root}/currencies/{$iso}/{$url}";
                }
            } elseif (in_array($name, ['deviceDetection', 'deviceDetectionJson'], true)) {
                $wanted[] = "{$root}/gameclient_html/devicedetection/current/{$url}";
            } else {
                $wanted[] = $this->resolve($game, $url);
            }
        }
        // newer clients list no language resource; their loader fetches
        // langlib/<lang>/<lang>.json — next to the page (touch clients, ntt/…_touch)
        // or one level up (dist/bundles clients) — and the rules text per language
        if ($languages === []) {
            foreach (self::LANGUAGES as $lang) {
                $found = $this->getMany(["{$game}/langlib/{$lang}/{$lang}.json", "{$gameDir}/langlib/{$lang}/{$lang}.json"], false);
                if (array_filter($found, fn ($body) => $body !== null) !== []) {
                    $languages[] = $lang;
                    $this->getMany(["{$gameDir}/rules/{$lang}/rulestext.json"], false);
                }
            }
        }
        $languages = array_values(array_unique($languages));
        if (! in_array('en', $languages, true)) {
            $this->missing[] = "{$gameDir}/langlib/en/ (language.xml or en.json)";
        }
        $bodies = $this->getMany(array_values(array_unique($wanted)), false);
        $this->follow($bodies, $game);

        // 3. what the client really loads, desktop + mobile
        if ($record) {
            $this->log('recording the page in a browser (desktop + mobile)');
            try {
                // the Selenium container runs one browser at a time: parallel
                // imports take turns here instead of timing out in its queue
                $urls = Cache::lock('selenium-recorder', 900)->block(1800, fn () => $this->recorder->record($page));
            } catch (\Throwable $e) {
                if ($xml === '') {
                    throw new RuntimeException('Browser recording failed, and the client has no resources.xml to mirror from: '.$e->getMessage(), 0, $e);
                }
                $urls = [];
                $this->warnings[] = 'Browser recording failed: '.$e->getMessage();
            }
            $prefix = $this->origin.'/'.$root.'/';
            $rels = [];
            foreach ($urls as $url) {
                if (str_starts_with($url, $prefix)) {
                    $rels[] = $this->resolve('', (string) parse_url($url, PHP_URL_PATH));
                }
            }
            $this->log(count($rels).' file(s) loaded by the page');
            $this->follow($this->getMany($rels, false), $game);
        }

        // 4. every audio clip in every format the engine might pick (Safari: mp3/m4a)
        $twins = [];
        foreach (array_keys(array_filter($this->seen)) as $rel) {
            if (preg_match('#^(.*/)(ogg|mp3|m4a)/([^/]+)\.(ogg|mp3|m4a)$#', $rel, $a)) {
                foreach (self::AUDIO_FORMATS as $fmt) {
                    $twins[] = "{$a[1]}{$fmt}/{$a[3]}.{$fmt}";
                }
            }
        }
        $this->getMany($twins, false);

        $files = array_keys(array_filter($this->seen));
        $bytes = array_sum(array_map(fn ($r) => (int) filesize($this->outDir.'/'.$r), $files));

        return ['entry' => $entry, 'game_dir' => $gameDir, 'root' => $root, 'languages' => $languages,
            'files' => count($files), 'bytes' => $bytes, 'missing' => array_values(array_unique($this->missing)), 'warnings' => $this->warnings];
    }

    /**
     * Follow what fetched configs point at: relative file paths in JSON
     * strings (loader images, …), audio clip lists, CSS url()s.
     *
     * @param  array<string, string|null>  $bodies
     */
    private function follow(array $bodies, string $game): void
    {
        $next = [];
        foreach ($bodies as $rel => $body) {
            if ($body === null) {
                continue;
            }
            if (str_ends_with($rel, '.css')) {
                $next = array_merge($next, $this->cssRefs($body, dirname($rel)));
            }
            if (! str_ends_with($rel, '.json')) {
                continue;
            }
            $json = json_decode($body, true);
            if (! is_array($json)) {
                continue;
            }
            // audio clip lists: {"clips": {"<group>": {"<clip>": {…}}}} → multimedia/audio/webAudio/<group>/<fmt>/<clip>.<fmt>
            if (str_contains($rel, '/config/audio/') && is_array($json['clips'] ?? null)) {
                foreach ($json['clips'] as $group => $clips) {
                    foreach (array_keys((array) $clips) as $clip) {
                        foreach (self::AUDIO_FORMATS as $fmt) {
                            $next[] = "{$game}/multimedia/audio/webAudio/{$group}/{$fmt}/{$clip}.{$fmt}";
                        }
                    }
                }
            }
            array_walk_recursive($json, function ($v) use (&$next, $game, $rel) {
                if (is_string($v) && strlen($v) < 300 && ! str_starts_with($v, 'data:')
                    && preg_match('#^(?!https?:|//)[\w.\-/]+\.(png|jpe?g|webp|gif|svg|json|xml|js|css|ogg|mp3|m4a|mp4|webm|woff2?|ttf|ico|atlas)$#i', $v)) {
                    $next[] = $this->resolve($game, $v);
                    $next[] = $this->resolve(dirname($rel), $v);
                }
            });
        }
        $next = array_values(array_diff(array_unique($next), array_keys($this->seen)));
        if ($next !== []) {
            $this->follow($this->getMany($next, false), $game);
        }
    }

    /** @return list<string> */
    private function refs(string $html, string $dir): array
    {
        preg_match_all('/\b(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $m);

        return array_values(array_unique(array_map(fn ($r) => $this->resolve($dir, $r),
            array_filter($m[1], fn ($r) => ! preg_match('#^(?:[a-z]+:|//|/|\#)#i', $r)))));
    }

    /** @return list<string> */
    private function cssRefs(string $css, string $dir): array
    {
        preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css, $m);

        return array_values(array_unique(array_map(fn ($r) => $this->resolve($dir, $r),
            array_filter($m[1], fn ($r) => ! preg_match('#^(?:[a-z]+:|//|/|\#)#i', $r)))));
    }

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
            $out[] = rawurldecode($seg);   // a file's own name: `deal animation`, not `deal%20animation`
        }

        return implode('/', $out);
    }

    /** A mirrored path as a URL path again: each segment encoded. */
    private function urlPath(string $rel): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    private function get(string $rel, bool $required): ?string
    {
        return $this->getMany([$rel], $required)[$rel] ?? null;
    }

    /**
     * @param  list<string>  $rels
     * @return array<string, string|null>
     */
    private function getMany(array $rels, bool $required): array
    {
        $out = [];
        $todo = [];
        foreach (array_unique($rels) as $rel) {
            if (str_contains($rel, '{{')) {
                continue;   // a client template's placeholder (`{{=it.imageSrc}}`), not a file
            }
            if (array_key_exists($rel, $this->seen)) {
                $out[$rel] = $this->seen[$rel] ? (string) file_get_contents($this->outDir.'/'.$rel) : null;
            } else {
                $todo[] = $rel;
            }
        }
        foreach (array_chunk($todo, self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(60)->get($this->origin.'/'.$this->urlPath($rel)),
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

    private function store(string $rel, string $body): void
    {
        $target = $this->outDir.'/'.$rel;
        File::ensureDirectoryExists(dirname($target));
        file_put_contents($target, $body);
    }

    private function log(string $message): void
    {
        if ($this->progress) {
            ($this->progress)($message);
        }
    }
}
