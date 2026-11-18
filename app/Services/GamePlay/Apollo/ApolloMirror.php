<?php

namespace App\Services\GamePlay\Apollo;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors an Apollo Games HTML5 (cocos2d) client off its static host
 * (`https://static.joker-entry.link/ag/`), keeping the host's hierarchy:
 *
 *   iframe.html                       the launch page (bundle entry): reads
 *                                     `socketURL` / `language` off its URL
 *   common/envconfig.js, main.js      the lobby layer: opens the server
 *                                     connection, sends `casino.init`, then
 *                                     loads the game folder the init names
 *   l10n/web_lang.json, img/…         its texts and images
 *   <game>_ag/index.json              versions; css/style.css, boot.js,
 *                                     game.min.js, benchmbundle.js, …
 *   <game>_ag/res/…                   every `"res/…"` path game.min.js names,
 *                                     plus what those sheets / layouts name
 */
class ApolloMirror
{
    public const string HOST = 'https://static.joker-entry.link/ag/';

    private const int CONCURRENCY = 16;

    private const string EXT = 'png|jpg|jpeg|webp|gif|plist|json|ExportJson|csb|fnt|atlas|mp3|ogg|m4a|wav|woff2?|ttf|otf|css|js|xml|txt';

    private string $outDir = '';

    /** @var array<string, bool> host-relative path => fetched ok */
    private array $seen = [];

    /** @var list<string> */
    private array $missing = [];

    private int $reported = -1;

    /** @var (callable(string): void)|null */
    private $progress;

    /**
     * @param  string  $folder  the game's folder on the host, e.g. lucky81_ag (the init's `name`)
     * @return array{entry: string, folder: string, files: int, bytes: int, missing: list<string>, warnings: list<string>, languages: list<string>}
     */
    public function mirror(string $folder, string $outDir, ?callable $progress = null): array
    {
        if (! preg_match('/^[a-z0-9_]+$/i', $folder)) {
            throw new RuntimeException("Not an Apollo game folder: {$folder}");
        }
        $this->outDir = rtrim($outDir, '/');
        $this->progress = $progress;
        $this->seen = $this->missing = [];
        File::deleteDirectory($this->outDir);
        File::ensureDirectoryExists($this->outDir);

        // 1. the lobby layer
        $this->get('iframe.html', true) ?? throw new RuntimeException('Apollo launch page not found: '.self::HOST.'iframe.html');
        $main = (string) $this->get('common/main.js', true);
        $this->getMany(['common/envconfig.js', 'l10n/web_lang.json', 'css/style.css', 'img/logo.png', 'img/arrow.png', 'favicon.ico'], false);
        // what the lobby layer loads by url(…): its font (main.js's FontFace), the CSS images
        $lobbyRefs = [];
        foreach (['common/main.js' => '', 'css/style.css' => 'css'] as $file => $dir) {
            preg_match_all('#url\(\s*["\']?([^"\')?\#]+)#i', $this->read($file), $u);
            foreach ($u[1] as $ref) {
                if (! str_starts_with($ref, 'data:') && ! str_contains($ref, '//') && ! str_starts_with($ref, '/')) {
                    $lobbyRefs[] = $this->resolve($dir, $ref);
                }
            }
        }
        $this->getMany($lobbyRefs, false);
        if (! str_contains($main, "\"{$folder}\"")) {
            throw new RuntimeException("The Apollo lobby (common/main.js) doesn't know the game folder {$folder}");
        }

        // 2. the game folder
        $index = json_decode((string) $this->get("{$folder}/index.json", true), true);
        if (! is_array($index)) {
            throw new RuntimeException("{$folder}/index.json is not JSON");
        }
        $this->getMany(["{$folder}/css/style.css", "{$folder}/boot.js", "{$folder}/game.min.js"], true);
        $this->getMany(["{$folder}/benchmbundle.js", "{$folder}/blackList.json", "{$folder}/project.json"], false);
        $js = $this->read("{$folder}/game.min.js").$this->read("{$folder}/boot.js");
        preg_match_all('#["\'](res/[^"\'\n?]+\.(?:'.self::EXT.'))(?:\?[^"\']*)?["\']#i', $js, $m);
        $wanted = array_map(fn ($p) => "{$folder}/{$p}", array_unique($m[1]));
        preg_match_all('#url\(\s*["\']?([^"\')?]+)#i', $this->read("{$folder}/css/style.css"), $css);
        foreach ($css[1] as $u) {
            if (! str_starts_with($u, 'data:') && ! str_contains($u, '//')) {
                $wanted[] = $this->resolve("{$folder}/css", $u);
            }
        }
        $this->getMany($wanted, false);   // some are dead (commented-out CSS, unused sheets)

        // 3. what the sheets and layouts name as files — a plist's texture, a
        // cocos layout's `textures` / `texturesPng` / `path` / … — relative to
        // the file, or to the game folder (not their sprite-frame names: those
        // are frames inside a sheet)
        $queue = array_keys(array_filter($this->seen));
        for ($round = 0; $round < 4 && $queue !== []; $round++) {
            $next = [];
            foreach ($queue as $rel) {
                foreach ($this->fileRefs($rel) as $ref) {
                    // cocos studio layouts name files relative to their parent folder
                    foreach ([$this->resolve(dirname($rel), $ref), $this->resolve(dirname($rel, 2), $ref), "{$folder}/".ltrim($ref, '/'), "{$folder}/res/".ltrim($ref, '/')] as $cand) {
                        if (! isset($this->seen[$cand])) {
                            $next[] = $cand;
                        }
                    }
                }
            }
            $before = array_keys(array_filter($this->seen));
            $this->getMany(array_values(array_unique($next)), false);
            $queue = array_values(array_diff(array_keys(array_filter($this->seen)), $before));
        }

        $languages = [];
        $lang = json_decode($this->read("{$folder}/res/l10n/web_lang.json") ?: $this->read('l10n/web_lang.json'), true);
        if (is_array($lang)) {
            $languages = array_values(array_filter(array_map('strval', array_keys($lang)), fn ($k) => strlen($k) <= 3));
        }

        $files = File::allFiles($this->outDir, true);

        return [
            'entry' => 'iframe.html',
            'folder' => $folder,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => array_values(array_unique($this->missing)),
            'warnings' => [],
            'languages' => $languages,
            'versions' => $index,
        ];
    }

    /** @return list<string> file names a sheet / layout / font refers to */
    private function fileRefs(string $rel): array
    {
        $text = $this->read($rel);
        $refs = [];
        if (preg_match('/\.plist$/i', $rel)) {
            preg_match_all('#<key>(?:textureFileName|realTextureFileName)</key>\s*<string>([^<]+)</string>#', $text, $m);
            $refs = $m[1];
        } elseif (preg_match('/\.(json|ExportJson)$/i', $rel) && ! str_ends_with($rel, 'web_lang.json')) {
            preg_match_all('#"(?:textures|texturesPng|path|plist|plistFile|fntFile|textureFile|file|image|images|atlas|skeleton)"\s*:\s*(\[[^\]]*\]|"[^"]*")#i', $text, $m);
            foreach ($m[1] as $v) {
                preg_match_all('#"([^"]+\.(?:png|jpg|jpeg|webp|plist|json|ExportJson|fnt|atlas|csb|ttf|otf|woff2?))"#i', $v, $f);
                array_push($refs, ...$f[1]);
            }
        } elseif (preg_match('/\.(fnt|atlas)$/i', $rel)) {
            preg_match_all('#(?:file="|^)([^"\n]+\.(?:png|jpg))#mi', $text, $m);
            $refs = $m[1];
        }

        return array_values(array_unique(array_filter(array_map('trim', $refs), fn ($r) => $r !== '' && ! str_contains($r, '..') && ! str_contains($r, '//'))));
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

    /** A host answering a missing file with its 200 "OK" / HTML page, not the file. */
    private function isHtmlFallback(string $rel, Response $r): bool
    {
        return ! str_ends_with($rel, '.html') && (trim($r->body()) === 'OK' || str_contains((string) $r->header('Content-Type'), 'text/html'));
    }
}
