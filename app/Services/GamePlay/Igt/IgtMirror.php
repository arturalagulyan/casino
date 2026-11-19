<?php

namespace App\Services\GamePlay\Igt;

use App\Services\GamePlay\Amatic\AmaticPageRecorder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors an IGT HTML5 ("Skateboard" platform) client off its static host
 * (`https://static.joker-entry.link/ih/`), keeping the host's hierarchy:
 *
 *   commonStatic/iframeDesktop.html     the page (bundle entry): reads
 *                                       `currency`, `denom`, `gameStatic` and
 *                                       `server` off its URL
 *   commonStatic/js/…, skins/DV07/…     platform kernel, console bar
 *   <game>/splash.html, launcher.html   the game, require.js modules under
 *   <game>/core-…, games-…/…            versioned folders
 *
 * The client builds its file list in code (require.js), so the list is a
 * browser recording of a live demo ({@see AmaticPageRecorder}) — the client
 * preloads everything, free-spin graphics included.
 */
class IgtMirror
{
    public const string HOST = 'https://static.joker-entry.link/ih/';

    private const int CONCURRENCY = 16;

    public function __construct(private readonly AmaticPageRecorder $recorder) {}

    /**
     * @param  string  $page  the live client page (…/ih/commonStatic/iframeDesktop.html?…&gameStatic=…/ih/<game>/&server=…)
     * @return array{entry: string, folder: string, files: int, bytes: int, missing: list<string>, warnings: list<string>, languages: list<string>}
     */
    public function mirror(string $page, string $outDir, ?callable $progress = null): array
    {
        parse_str((string) parse_url($page, PHP_URL_QUERY), $q);
        $folder = trim(str_replace(self::HOST, '', (string) ($q['gameStatic'] ?? '')), '/');
        if (! str_starts_with($page, self::HOST) || $folder === '' || str_contains($folder, '/')) {
            throw new RuntimeException("Not an IGT client page (…/ih/commonStatic/iframeDesktop.html?gameStatic=…): {$page}");
        }
        File::deleteDirectory($outDir);
        File::ensureDirectoryExists($outDir);

        $progress && $progress('recording the page in a browser…');
        $wanted = ['commonStatic/iframeDesktop.html'];
        foreach ($this->recorder->record($page) as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_starts_with($url, self::HOST) && $path !== '') {
                $wanted[] = rawurldecode(substr($path, strlen('/ih/')));
            }
        }
        $wanted = array_values(array_unique(array_filter($wanted, fn ($p) => str_starts_with($p, 'commonStatic/') || str_starts_with($p, "{$folder}/"))));
        if (count(array_filter($wanted, fn ($p) => str_starts_with($p, "{$folder}/"))) < 20) {
            throw new RuntimeException("The browser recording loaded almost nothing of {$folder} — did the page start?");
        }

        $missing = [];
        foreach (array_chunk($wanted, self::CONCURRENCY) as $i => $chunk) {
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
            $progress && $progress(sprintf('%d / %d files', min(count($wanted), ($i + 1) * self::CONCURRENCY), count($wanted)));
        }

        // the page clears localStorage on load — on our origin that is the
        // casino's own storage, not just the game's
        $entry = "{$outDir}/commonStatic/iframeDesktop.html";
        File::put($entry, str_replace('localStorage.clear();', '', (string) File::get($entry)));

        $languages = [];
        foreach (File::directories("{$outDir}/{$folder}") as $d) {
            foreach (glob("{$d}/*/skins/default/multilanguage/*", GLOB_ONLYDIR) ?: [] as $l) {
                $languages[] = basename($l);
            }
        }
        $files = File::allFiles($outDir, true);

        return [
            'entry' => 'commonStatic/iframeDesktop.html',
            'folder' => $folder,
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files)),
            'missing' => $missing,
            'warnings' => [],
            'languages' => array_values(array_unique($languages)) ?: ['en'],
        ];
    }
}
