<?php

namespace App\Services\GamePlay\Novoline;

use App\Services\GamePlay\Amatic\AmaticPageRecorder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors a Greentube (Novoline) HTML5 client off its static host
 * (`https://static.joker-entry.link/gt/`):
 *
 *   index.html               our page (bundle entry), built from Betboy's
 *                            launch page: picks the desktop or mobile build,
 *                            starts the client against window.NOVO.host
 *   <game>/jsout.js, …       the desktop build (images as .webp)
 *   <game>Mobile/…           the phone build
 *
 * A build loads some files only when first needed (win animations — "video"
 * atlases —, feature graphics), so besides a browser recording of the page
 * ({@see AmaticPageRecorder}, desktop and phone) every file the build's
 * manifests (images.json, video.json, sound_multiple.json) name is fetched.
 */
class NovolineMirror
{
    public const string HOST = 'https://static.joker-entry.link/gt/';

    private const int CONCURRENCY = 24;

    public function __construct(private readonly AmaticPageRecorder $recorder) {}

    /**
     * @param  string  $page  the launch page (…joker-entry.link/, serves the client page)
     * @param  string  $html  that page's HTML (desktop)
     * @return array{entry: string, folder: string, files: int, bytes: int, missing: list<string>, warnings: list<string>}
     */
    public function mirror(string $page, string $html, string $folder, string $outDir, ?callable $progress = null): array
    {
        if (! str_contains($html, self::HOST.$folder.'/jsout.js')) {
            throw new RuntimeException("Not a Greentube client page for {$folder}");
        }
        File::deleteDirectory($outDir);
        File::ensureDirectoryExists($outDir);

        $progress && $progress('recording the page in a browser…');
        $recorded = [];
        foreach ($this->recorder->record($page) as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_starts_with($url, self::HOST) && $path !== '') {
                $rel = rawurldecode(substr($path, strlen('/gt/')));
                if (str_starts_with($rel, "{$folder}/") || str_starts_with($rel, "{$folder}Mobile/")) {
                    $recorded[] = $rel;
                }
            }
        }
        $recorded = array_values(array_unique($recorded));
        if (count(array_filter($recorded, fn ($p) => str_starts_with($p, "{$folder}/"))) < 20) {
            throw new RuntimeException("The browser recording loaded almost nothing of {$folder} — did the page start?");
        }
        $builds = array_values(array_filter([$folder, "{$folder}Mobile"], fn ($b) => in_array("{$b}/jsout.js", $recorded, true)));

        $missing = [];
        $fetch = function (array $list, bool $required) use ($outDir, &$missing, $progress) {
            foreach (array_chunk($list, self::CONCURRENCY) as $i => $chunk) {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (string $rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(60)->get(self::HOST.str_replace(' ', '%20', $rel)),
                    $chunk,
                ));
                foreach ($chunk as $rel) {
                    $r = $responses[$rel] ?? null;
                    if (! $r instanceof Response || ! $r->successful()) {
                        if ($required) {
                            $missing[] = $rel;
                        }

                        continue;
                    }
                    File::ensureDirectoryExists(dirname("{$outDir}/{$rel}"));
                    File::put("{$outDir}/{$rel}", $r->body());
                }
                if ($progress && $i % 10 === 0) {
                    $progress(sprintf('%s %d / %d', $required ? 'files' : 'manifest files', min(count($list), ($i + 1) * self::CONCURRENCY), count($list)));
                }
            }
        };
        $fetch($recorded, true);
        if ($missing !== []) {   // the host drops the odd request under load: once more, one by one
            [$retry, $missing] = [$missing, []];
            foreach ($retry as $rel) {
                $fetch([$rel], true);
            }
        }
        $progress && $progress('reading the manifests…');
        $optional = [];
        foreach ($builds as $b) {   // a build loads its images as .webp (desktop) or as they are (phone) — the recording tells
            $webp = array_filter($recorded, fn ($p) => str_starts_with($p, "{$b}/") && str_ends_with($p, '.webp')) !== [];
            $optional = array_merge($optional, $this->manifest($b, $webp, $outDir));
        }
        $fetch(array_values(array_filter(array_unique($optional), fn (string $rel) => ! is_file("{$outDir}/{$rel}"))), false);

        File::put("{$outDir}/index.html", $this->page($html, $folder, in_array("{$folder}Mobile", $builds, true)));
        $files = File::allFiles($outDir, true);

        return [
            'entry' => 'index.html',
            'folder' => $folder,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => $missing,
            'warnings' => [],
        ];
    }

    /**
     * An earlier mirror completed: whatever its builds' manifests name that it
     * lacks, fetched (what the host does not have is skipped).
     *
     * @return int files added
     */
    public function topUp(string $outDir, string $folder): int
    {
        $wanted = [];
        foreach ([$folder, "{$folder}Mobile"] as $b) {
            if (is_file("{$outDir}/{$b}/jsout.js")) {
                $webp = $this->hasWebp("{$outDir}/{$b}");   // desktop builds load .webp, phone ones not
                $wanted = array_merge($wanted, $this->manifest($b, $webp, $outDir));
            }
        }
        $wanted = array_values(array_filter(array_unique($wanted), fn (string $rel) => ! is_file("{$outDir}/{$rel}")));
        $added = 0;
        foreach (array_chunk($wanted, self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $rel) => $pool->as($rel)->withUserAgent('Mozilla/5.0')->timeout(60)->get(self::HOST.str_replace(' ', '%20', $rel)),
                $chunk,
            ));
            foreach ($chunk as $rel) {
                $r = $responses[$rel] ?? null;
                if ($r instanceof Response && $r->successful()) {
                    File::ensureDirectoryExists(dirname("{$outDir}/{$rel}"));
                    File::put("{$outDir}/{$rel}", $r->body());
                    $added++;
                }
            }
        }

        return $added;
    }

    private function hasWebp(string $dir): bool
    {
        foreach (File::allFiles($dir) as $f) {
            if (str_ends_with($f->getFilename(), '.webp')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files a build's manifests name (paths relative to the static host).
     * Images may be stored with a `.webp` suffix (desktop) or not (mobile), and
     * may carry a frame map `<image>.json` (sprite sheets); a video is
     * `<name>.0001.json` + `<name>.0001.<format>` per atlas. Many builds name
     * their files relative to package roots (`/tresamigos/res/…` under
     * `/com/greentube/slot/client`): the roots are read off the files the
     * mirror already has, and every key tried under them.
     *
     * @return list<string>
     */
    private function manifest(string $build, bool $webp, string $outDir): array
    {
        $get = fn (string $f) => (array) (Http::withUserAgent('Mozilla/5.0')->timeout(60)->get(self::HOST."{$build}/{$f}")->json() ?? []);
        $skip = fn (string $k) => str_contains($k, '/brasilbingo/');   // a bingo room's images every build lists
        $suffix = $webp ? '.webp' : '';
        $images = array_values(array_filter(array_keys((array) ($get('images.json')['images'] ?? [])), fn ($k) => ! $skip($k)));
        $sounds = array_values(array_filter(array_keys((array) ($get('sound_multiple.json')['sounds'] ?? [])), fn ($k) => ! $skip($k)));
        $videos = (array) ($get('video.json')['videos'] ?? []);

        // the build's files on disk ("/com/…"), and the roots its keys sit under
        $have = [];
        if (is_dir("{$outDir}/{$build}")) {
            foreach (File::allFiles("{$outDir}/{$build}") as $f) {
                $have['/'.str_replace('\\', '/', $f->getRelativePathname())] = true;
            }
        }
        $count = [];
        foreach (array_keys($have) as $p) {
            foreach ([[$images, $suffix], [$sounds, '.mp3']] as [$keys, $ext]) {
                foreach ($keys as $k) {
                    if (str_ends_with($p, $k.$ext)) {
                        $root = substr($p, 0, -strlen($k.$ext));
                        $count[$root] = ($count[$root] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($count);
        $roots = array_values(array_unique(['', ...array_keys(array_filter($count, fn (int $n) => $n >= 3))]));
        // a key found on disk tells its folder's root; a key not found takes its folder's (or a parent's)
        $folderRoot = [];
        foreach ([[$images, $suffix], [$sounds, '.mp3']] as [$keys, $ext]) {
            foreach ($keys as $k) {
                foreach ($roots as $r) {
                    if (isset($have[$r.$k.$ext])) {
                        $folderRoot[dirname($k)] ??= $r;

                        break;
                    }
                }
            }
        }
        $fallback = array_slice($roots, 0, 4);   // '' and the three commonest
        $under = function (string $k, string $ext) use ($roots, $have, $folderRoot, $fallback): array {
            foreach ($roots as $r) {
                if (isset($have[$r.$k.$ext])) {
                    return [$r];
                }
            }
            for ($d = dirname($k); $d !== '/' && $d !== '.' && $d !== ''; $d = dirname($d)) {
                if (isset($folderRoot[$d])) {
                    return [$folderRoot[$d]];
                }
            }

            return $fallback;
        };

        $out = [];
        foreach ($images as $k) {
            foreach ($under($k, $suffix) as $r) {
                array_push($out, $build.$r.$k.$suffix, $build.$r.$k.$suffix.'.json');
            }
        }
        foreach ($videos as $k => $v) {
            $format = (string) ($v['format'] ?? 'png');
            foreach ($under($k, isset($v['atlases']) ? ".0001.{$format}{$suffix}" : ".{$format}{$suffix}") as $r) {
                // atlases `<name>.0001…` — or, an entry without a count, one sheet named `<name>` (Sea Sirens)
                $names = isset($v['atlases'])
                    ? array_map(fn (int $i) => sprintf('%s%s%s.%04d', $build, $r, $k, $i), range(1, max(1, (int) $v['atlases'])))
                    : ["{$build}{$r}{$k}"];
                foreach ($names as $n) {   // an atlas' frame map: `<n>.json`, or (newer builds) named after its image
                    array_push($out, "{$n}.{$format}{$suffix}", "{$n}.json", "{$n}.{$format}{$suffix}.json");
                }
                if (($v['preferred_format'] ?? '') === 'stream') {   // played as a video file where the browser can
                    array_push($out, "{$build}{$r}{$k}.webm", "{$build}{$r}{$k}.mp4");
                }
            }
        }
        foreach ($sounds as $k) {
            foreach ($under($k, '.mp3') as $r) {
                $out[] = $build.$r.$k.'.mp3';
            }
        }

        return $out;
    }

    /**
     * Our entry page: Betboy's launch page with its static host and session
     * stripped — the build picked by device, the client's server host taken
     * from window.NOVO (set by our launch), no analytics calls.
     */
    private function page(string $html, string $folder, bool $mobile): string
    {
        $html = (string) preg_replace('#<base\s[^>]*>\s*#i', '', $html);
        $html = (string) preg_replace('#^.*window\.top\.AA\..*$\n?#m', '', $html);
        $pick = $mobile
            ? "var NOVOF=(/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)||(navigator.maxTouchPoints>1&&/Macintosh/.test(navigator.userAgent)))?'{$folder}Mobile':'{$folder}',NOVOB=document.baseURI.replace(/[^\\/]*\$/,'')+NOVOF+'/';"
            : "var NOVOF='{$folder}',NOVOB=document.baseURI.replace(/[^\\/]*\$/,'')+NOVOF+'/';";
        $html = str_replace("var s = document.createElement('script');", $pick."\n    var s = document.createElement('script');", $html);
        $html = str_replace(
            ["'".self::HOST."{$folder}/jsout.js'", "'".self::HOST."{$folder}/'"],
            ["NOVOB+'jsout.js'", 'NOVOB'],
            $html,
        );
        $html = (string) preg_replace('#"hostname":\s*"[^"]*"#', '"hostname": window.NOVO.host', $html);
        $html = (string) preg_replace('#"roomid":\s*"[^"]*"#', '"roomid": window.NOVO.room', $html);
        $html = (string) preg_replace('#"(closeurl|helpurl)":\s*"[^"]*"#', '"$1": ""', $html);
        // the launcher's session / static hosts (BASE, SESSION…): not ours — point them at the page's folder
        $html = (string) preg_replace('#https://[a-z0-9]+\.joker-entry\.link/gt/#', '', $html);
        $html = str_replace(self::HOST, '', $html);
        if (str_contains($html, 'joker-entry.link')) {
            throw new RuntimeException('The launch page still names Betboy hosts after rewriting');
        }

        return $html;
    }
}
