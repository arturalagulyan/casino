<?php

namespace App\Services\GamePlay;

use App\Models\GameBundle;
use App\Models\GameTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Handles the admin "upload the game's front-end files" flow: takes a .zip,
 * extracts it into a versioned folder on the `game_bundles` disk, records a
 * GameBundle row and makes it the active one.
 *
 * ← replaces the legacy loose public/games/<Code> folders that shipped in the repo.
 */
class BundleManager
{
    private const string DISK = 'game_bundles';

    /**
     * Files a game bundle must never contain (uploaded zips are untrusted).
     *
     * @var list<string>
     */
    private const array BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps'];

    public function store(GameTemplate $template, UploadedFile $zip, ?User $by = null, ?string $entry = null, ?string $notes = null): GameBundle
    {
        if (! $zip->isValid() || strtolower($zip->getClientOriginalExtension()) !== 'zip') {
            throw new RuntimeException('Upload must be a .zip archive.');
        }

        $checksum = hash_file('sha256', $zip->getRealPath());
        $version = (int) ($template->bundles()->max('version') ?? 0) + 1;
        $slug = Str::slug($template->code) ?: 'game-'.$template->id;
        $relDir = "{$slug}/{$version}";
        $disk = Storage::disk(self::DISK);
        $absDir = $disk->path($relDir);

        // Start from a clean versioned dir — a re-run of the same version must
        // not leave stale files behind.
        File::deleteDirectory($absDir);
        File::ensureDirectoryExists($absDir);

        try {
            [$fileCount, $size, $foundEntry] = $this->extract($zip->getRealPath(), $absDir, $entry);
        } catch (\Throwable $e) {
            File::deleteDirectory($absDir);

            throw $e;
        }

        return DB::transaction(function () use ($template, $version, $relDir, $foundEntry, $size, $fileCount, $checksum, $by, $notes): GameBundle {
            $template->bundles()->update(['is_active' => false]);

            $bundle = new GameBundle([
                'version' => $version,
                'disk' => self::DISK,
                'path' => $relDir,
                'entry' => $foundEntry,
                'size' => $size,
                'file_count' => $fileCount,
                'checksum' => $checksum,
                'uploaded_by' => $by?->id,
                'is_active' => true,
                'notes' => $notes,
            ]);
            $template->bundles()->save($bundle);

            return $bundle;
        });
    }

    public function activate(GameBundle $bundle): void
    {
        DB::transaction(function () use ($bundle) {
            $bundle->template->bundles()->whereKeyNot($bundle->id)->update(['is_active' => false]);
            $bundle->update(['is_active' => true]);
        });
    }

    public function delete(GameBundle $bundle): void
    {
        if ($bundle->is_active) {
            throw new RuntimeException('Activate another version before deleting the active one.');
        }

        Storage::disk($bundle->disk)->deleteDirectory($bundle->path);
        $bundle->delete();
    }

    /**
     * @return array{0:int,1:int,2:string} [fileCount, totalBytes, entryFile]
     */
    private function extract(string $zipPath, string $destination, ?string $entry): array
    {
        $archive = new ZipArchive;

        if ($archive->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the zip archive.');
        }

        // A single wrapping folder (games often zip as "MyGame/...") is stripped.
        $prefix = $this->commonPrefix($archive);

        $count = 0;
        $bytes = 0;
        $names = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);
            $name = str_replace('\\', '/', $stat['name']); // Windows-made zips use backslashes

            if (str_ends_with($name, '/')) {
                continue;
            }

            $relative = $prefix !== '' && str_starts_with($name, $prefix)
                ? substr($name, strlen($prefix))
                : $name;

            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }

            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                $archive->close();
                throw new RuntimeException("Bundle contains an executable file ({$relative}). Front-end assets only.");
            }

            $target = $destination.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, $archive->getFromIndex($i));

            $count++;
            $bytes += (int) $stat['size'];
            $names[] = $relative;
        }

        $archive->close();

        $entryFile = $this->resolveEntry($names, $entry);

        if (! $entryFile) {
            File::deleteDirectory($destination);
            throw new RuntimeException('Bundle has no index.html (or the entry file you named). Nothing extracted.');
        }

        return [$count, $bytes, $entryFile];
    }

    private function commonPrefix(ZipArchive $archive): string
    {
        $top = null;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = str_replace('\\', '/', $archive->statIndex($i)['name']);
            $segment = explode('/', $name)[0];

            if ($segment === '' || $segment === $name) {
                return '';
            }

            $top ??= $segment;

            if ($segment !== $top) {
                return '';
            }
        }

        return $top ? $top.'/' : '';
    }

    /** @param list<string> $names */
    private function resolveEntry(array $names, ?string $entry): ?string
    {
        if ($entry && in_array($entry, $names, true)) {
            return $entry;
        }

        foreach (['index.html', 'index.htm', 'game.html'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        // deepest fallback: any *.html at the shallowest depth
        $html = array_values(array_filter($names, fn ($n) => str_ends_with(strtolower($n), '.html')));
        usort($html, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/'));

        return $html[0] ?? null;
    }
}
