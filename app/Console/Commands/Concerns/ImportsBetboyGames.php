<?php

namespace App\Console\Commands\Concerns;

use App\Models\Shop;
use App\Services\GamePlay\NetEnt\BetboySite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * What the Betboy importers (netent:fetch, apollo:fetch) share: the lobby
 * tile as poster, the --shop option, and zipping a mirror for BundleManager.
 * Needs the command's `--poster` and `--shop` options.
 */
trait ImportsBetboyGames
{
    /** @param array<string, mixed> $lobby */
    protected function poster(BetboySite $betboy, array $lobby, string $code): ?string
    {
        $url = (string) ($this->option('poster') ?: (($lobby['pic'] ?? '') !== '' ? $betboy->absolute((string) $lobby['pic']) : ''));
        if ($url === '') {
            return null;
        }
        try {
            $response = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $this->warn("  poster {$url} not available");

            return null;
        }
        // by the bytes, not the URL — Betboy serves WebP as `….jpg`
        $ext = match (getimagesizefromstring($response->body())['mime'] ?? null) {
            'image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            default => strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'png',
        };
        $rel = "game-posters/{$code}.{$ext}";
        Storage::disk('public')->put($rel, $response->body());
        $this->line("  poster {$url} → {$rel}");

        return $rel;
    }

    /** @return list<Shop>|null */
    protected function shops(): ?array
    {
        $wanted = (array) $this->option('shop');
        if ($wanted === []) {
            return Shop::query()->orderBy('id')->get()->all();
        }
        $shops = [];
        foreach ($wanted as $key) {
            $shop = Shop::query()->where(is_numeric($key) ? 'id' : 'name', $key)->first();
            if (! $shop) {
                $this->error("Shop '{$key}' not found.");

                return null;
            }
            $shops[] = $shop;
        }

        return $shops;
    }

    /** Zip `$dir` under a `<Code>/` wrapper, which BundleManager strips. */
    protected function zip(string $dir, string $code): string
    {
        $path = storage_path("app/game-zips/{$code}.zip");
        File::ensureDirectoryExists(dirname($path));
        if (is_file($path)) {
            unlink($path);
        }
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$path}");
        }
        foreach (File::allFiles($dir, true) as $file) {
            if (preg_match('/^\.[a-z]+-game\.json$/', $file->getFilename())) {   // the import's own manifest
                continue;
            }
            $archive->addFile($file->getPathname(), $code.'/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $archive->close();

        return $path;
    }
}
