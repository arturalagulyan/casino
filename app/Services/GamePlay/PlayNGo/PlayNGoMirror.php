<?php

namespace App\Services\GamePlay\PlayNGo;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a Play'n GO game client from Play'n GO's CDN into a local folder:
 * every file a real browser session loads (desktop and mobile, recorded in
 * the Selenium container), plus everything their resource manifests name —
 * texture sheets at every quality level, nested bundles, sounds, fonts.
 *
 * Files keep their CDN path under `cdn/` (`cdn/9.5.2-<game>.409/resources/…`,
 * `cdn/hera/…`), so the build's relative paths keep working and the game's
 * `resourceRoot` just points at our copy.
 */
class PlayNGoMirror
{
    public const string CDN = 'https://cdn.playngonetwork.com/';

    /** File types worth following when they appear inside a JSON / CSS file. */
    private const string FILE_RE = '/^[\w.\/${}\-]+\.(?:png|jpe?g|webp|avif|json|mp3|m4a|ogg|gltf|bin|woff2?|ttf|otf|css|js|ktx2?|basis|atlas|fnt|svg)$/i';

    public function __construct(private readonly string $webdriver = '') {}

    /**
     * @param  list<string>  $pages  launcher URLs to record (desktop, mobile…)
     * @return array{files: int, bytes: int, missing: list<string>}
     */
    public function mirror(array $pages, string $dir, ?callable $log = null): array
    {
        $log ??= fn (string $m) => null;
        $queue = [];
        foreach ($pages as $page) {
            $found = $this->record($page, str_contains($page, 'channel=mobile'));
            $log(count($found).' files loaded by '.(str_contains($page, 'channel=mobile') ? 'mobile' : 'desktop'));
            $queue = array_merge($queue, $found);
        }

        return $this->crawl(array_values(array_unique(array_filter($queue, fn ($u) => str_starts_with($u, self::CDN)))), $dir, $log);
    }

    /**
     * Fetch `$queue` (CDN URLs) and everything their manifests name into
     * `$dir/cdn/…`; files already there are read, not fetched again.
     *
     * @param  list<string>  $queue
     * @return array{files: int, bytes: int, missing: list<string>}
     */
    public function crawl(array $queue, string $dir, ?callable $log = null): array
    {
        $log ??= fn (string $m) => null;
        $seen = $missing = [];
        $bytes = 0;
        while ($queue !== []) {
            $url = $this->clean(array_shift($queue));
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $rel = substr($url, strlen(self::CDN));
            $path = $dir.'/cdn/'.$rel;
            if (is_file($path)) {
                $body = file_get_contents($path);
            } else {
                $res = Http::timeout(120)->retry(2, 500, throw: false)->get($url);
                if (! $res->successful()) {
                    $missing[] = $rel;

                    continue;
                }
                $body = $res->body();
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path, $body);
            }
            $bytes += strlen((string) $body);
            foreach ($this->references($url, (string) $body) as $next) {
                if (! isset($seen[$next])) {
                    $queue[] = $next;
                }
            }
        }
        $log(count($seen) - count($missing).' files mirrored ('.round($bytes / 1048576, 1).' MB), '.count($missing).' not on the CDN');

        return ['files' => count($seen) - count($missing), 'bytes' => $bytes, 'missing' => $missing];
    }

    /**
     * URLs a fetched file points at: file names in a JSON resource manifest
     * (resolved against the file's folder) and `url(…)` in a stylesheet.
     *
     * @return list<string>
     */
    private function references(string $url, string $body): array
    {
        $base = substr($url, 0, strrpos($url, '/') + 1);
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $found = [];
        if ($ext === 'json' || $ext === 'gltf') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $this->walk($json, $base, $found);
            }
        } elseif ($ext === 'css') {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $body, $m);
            foreach ($m[1] as $u) {
                if (! str_starts_with($u, 'data:')) {
                    $found[] = preg_match('#^https?://#', $u) ? $u : $this->resolve($base, $u);
                }
            }
        }

        return array_values(array_unique(array_map(fn ($u) => $this->clean($u), array_filter($found, fn ($u) => str_starts_with($u, self::CDN)))));
    }

    /**
     * Collect file references from a resource manifest. A bundle entry
     * (`{url: "bmpfonts/bmpfonts.json", data: {…}}`) names its own file and
     * the files inside its data are relative to that file's folder; only
     * file-valued keys count (frame names like "AB.png" inside atlases are not files).
     *
     * @param  list<string>  $found
     */
    private function walk(array $node, string $base, array &$found): void
    {
        $entryUrl = is_string($node['url'] ?? null) ? $node['url'] : null;
        if ($entryUrl !== null && isset($node['data']) && is_array($node['data'])) {
            $found[] = $this->resolve($base, $entryUrl);
            $this->walk($node['data'], substr($this->resolve($base, $entryUrl), 0, strrpos($this->resolve($base, $entryUrl), '/') + 1), $found);
            unset($node['data'], $node['url']);
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->walk($value, $base, $found);
            } elseif (is_string($value) && in_array($key, ['url', 'src', 'image', 'atlas', 'uri', 'file'], true)) {
                foreach (explode(',', $value) as $part) {   // fonts list several css files in one string
                    $part = trim($part);
                    if (preg_match(self::FILE_RE, $part) && ! str_contains($part, '${')) {
                        $found[] = preg_match('#^https?://#', $part) ? $part : $this->resolve($base, $part);
                    }
                }
            }
        }
    }

    private function resolve(string $base, string $rel): string
    {
        if (str_starts_with($rel, '/')) {
            return rtrim(self::CDN, '/').$rel;
        }
        $parts = explode('/', substr($base, strlen('https://')).$rel);
        $stack = [];
        foreach ($parts as $p) {
            if ($p === '..') {
                if (count($stack) > 1) {
                    array_pop($stack);
                }
            } elseif ($p !== '.' && $p !== '') {
                $stack[] = $p;
            }
        }

        return 'https://'.implode('/', $stack);
    }

    private function clean(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }

    /** @return list<string> URLs a real session loaded (status 200) */
    private function record(string $url, bool $mobile): array
    {
        $wd = rtrim($this->webdriver ?: (string) config('services.selenium.url', 'http://selenium:4444'), '/');
        $options = ['args' => ['--window-size=1280,800', '--autoplay-policy=no-user-gesture-required', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist']];
        if ($mobile) {
            $options['mobileEmulation'] = ['deviceName' => 'iPhone 12 Pro'];
        }
        $session = Http::timeout(120)->post("{$wd}/session", ['capabilities' => ['alwaysMatch' => [
            'browserName' => 'chrome', 'goog:loggingPrefs' => ['performance' => 'ALL'], 'goog:chromeOptions' => $options,
        ]]])->json('value.sessionId');
        if (! $session) {
            throw new RuntimeException("No WebDriver session at {$wd} — Play'n GO clients are recorded in a browser (Selenium)");
        }
        $s = "{$wd}/session/{$session}";
        $found = [];
        try {
            Http::timeout(120)->post("{$s}/url", ['url' => $url]);
            for ($t = 0; $t < 14; $t++) {
                sleep(5);
                foreach ((array) Http::timeout(120)->post("{$s}/se/log", ['type' => 'performance'])->json('value') as $entry) {
                    $m = json_decode((string) ($entry['message'] ?? ''), true)['message'] ?? [];
                    if (($m['method'] ?? '') === 'Network.responseReceived' && (int) ($m['params']['response']['status'] ?? 0) === 200) {
                        $found[] = (string) $m['params']['response']['url'];
                    }
                }
                // past the intro screen, then a spin, so the main game's resources load too
                if ($t === 8 || $t === 10) {
                    Http::timeout(60)->post("{$s}/actions", ['actions' => [['type' => 'pointer', 'id' => 'm', 'parameters' => ['pointerType' => 'mouse'], 'actions' => [
                        ['type' => 'pointerMove', 'duration' => 0, 'x' => $mobile ? 195 : 636, 'y' => $mobile ? 700 : 555, 'origin' => 'viewport'],
                        ['type' => 'pointerDown', 'button' => 0], ['type' => 'pointerUp', 'button' => 0],
                    ]]]]);
                }
            }
        } finally {
            Http::timeout(60)->delete($s);
        }

        return $found;
    }
}
