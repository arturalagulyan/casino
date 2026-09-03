<?php

namespace App\Console\Commands;

use App\Models\GameBundle;
use App\Services\GamePlay\BundleEntryResolver;
use App\Services\Legacy\LegacyGameReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-picks the entry HTML for bundles that are already on disk.
 *
 * The original legacy import used a naive "first .html by depth" heuristic and
 * landed ~120 bundles on a decoy (Wazdan → help.html, NetGame → browserChecker,
 * Playtech → the GWT platform loader). {@see BundleEntryResolver} now knows the
 * per-provider layouts; this command re-runs it against each bundle's existing
 * files and rewrites `game_bundles.entry` — no files are moved or re-copied.
 */
class ReresolveBundlesCommand extends Command
{
    protected $signature = 'bundles:reresolve
        {--dry-run : Report what would change, write nothing}
        {--only= : Comma list of game codes to limit to}
        {--all : Also re-check bundles whose current entry still resolves the same}';

    protected $description = 'Re-pick the entry HTML for already-registered game bundles';

    public function handle(BundleEntryResolver $resolver): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $dry = (bool) $this->option('dry-run');

        $query = GameBundle::query()->where('is_active', true)->with('template');
        if ($only !== []) {
            $query->whereHas('template', fn ($q) => $q->whereIn('code', $only));
        }

        $changed = $unchanged = $lost = 0;
        $rows = [];

        $query->orderBy('id')->chunk(200, function ($bundles) use ($resolver, $dry, &$changed, &$unchanged, &$lost, &$rows) {
            foreach ($bundles as $bundle) {
                if ($bundle->entry === LegacyGameReader::SLOT_EVENT_SHELL) {
                    $unchanged++;

                    continue;   // no HTML — the shell is request-time
                }
                $code = (string) $bundle->template->code;
                $files = array_map(
                    fn (string $p) => ltrim(substr($p, strlen($bundle->path) + 1), '/'),
                    Storage::disk($bundle->disk)->allFiles($bundle->path),
                );

                $entry = $resolver->resolve($files, $code);

                if ($entry === null) {
                    $lost++;
                    $rows[] = [$code, $bundle->entry, '<fg=red>(no entry resolvable)</>'];

                    continue;
                }
                if ($entry === $bundle->entry) {
                    $unchanged++;
                    if ($this->option('all')) {
                        $rows[] = [$code, $bundle->entry, '<fg=gray>= unchanged</>'];
                    }

                    continue;
                }

                $changed++;
                $rows[] = [$code, $bundle->entry, "<fg=green>{$entry}</>"];
                if (! $dry) {
                    $bundle->update(['entry' => $entry]);
                }
            }
        });

        if ($rows !== []) {
            $this->table(['Code', 'Old entry', $dry ? 'Would become' : 'New entry'], $rows);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s  changed=%d  unchanged=%d  unresolvable=%d',
            $dry ? 'Dry run —' : 'Done.',
            $changed,
            $unchanged,
            $lost,
        ));

        return self::SUCCESS;
    }
}
