<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ImportsBetboyGames;
use App\Enums\ClientProtocol;
use App\Models\GameTemplate;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\Novoline\NovolineMirror;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Complete earlier Novoline mirrors (`storage/app/game-fetch/<Code>`) with the
 * files their builds' manifests name that they lack — loaded only when first
 * needed, so a browser recording misses them — and upload the result as the
 * template's new bundle. No Betboy session is opened (no launch is spent).
 *
 *   php artisan novoline:topup TresAmigos
 *   php artisan novoline:topup --all
 */
class NovolineTopUpCommand extends Command
{
    use ImportsBetboyGames;

    protected $signature = 'novoline:topup
        {code? : Template code}
        {--all : Every Novoline template with a mirror}';

    protected $description = 'Complete Novoline client mirrors from their manifests and re-upload the bundles';

    public function handle(NovolineMirror $mirror, BundleManager $bundles): int
    {
        $templates = GameTemplate::query()->where('client_protocol', ClientProtocol::Novoline)
            ->when(! $this->option('all'), fn ($q) => $q->where('code', (string) $this->argument('code')))
            ->orderBy('id')->get();
        if ($templates->isEmpty()) {
            $this->error('No such Novoline template.');

            return self::FAILURE;
        }
        foreach ($templates as $template) {
            $dir = storage_path("app/game-fetch/{$template->code}");
            $manifest = "{$dir}/.novoline-game.json";
            if (! is_file($manifest)) {
                $this->warn("{$template->code}: no mirror at {$dir} — skipped");

                continue;
            }
            $m = (array) json_decode((string) file_get_contents($manifest), true);
            $added = $mirror->topUp($dir, (string) $m['folder']);
            if ($added === 0) {
                $this->line("{$template->code}: complete");

                continue;
            }
            $files = File::allFiles($dir, true);
            File::put($manifest, json_encode(array_merge($m, ['files' => count($files),
                'bytes' => array_sum(array_map(fn ($f) => $f->getSize(), $files))]), JSON_UNESCAPED_SLASHES));
            $zip = $this->zip($dir, $template->code);
            $bundle = $bundles->store($template, new UploadedFile($zip, basename($zip), 'application/zip', null, true),
                entry: (string) $m['entry'], notes: Str::limit("Novoline client mirror, topped up (novoline:topup) — {$m['folder']}", 250));
            File::delete($zip);
            $this->info("{$template->code}: {$added} file(s) added — bundle v{$bundle->version}, {$bundle->file_count} files");
        }

        return self::SUCCESS;
    }
}
