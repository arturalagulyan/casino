<?php

namespace App\Console\Commands;

use App\Models\GameTemplate;
use App\Services\GamePlay\BundleEntryResolver;
use Illuminate\Console\Command;

/**
 * Fills in a clean human `title` for templates that still carry the raw code as
 * their name (`AncientEgypt`, `AncientEgyptClassic`) or have no title at all.
 *
 * The provider suffix stays on `code` (it's the bundle asset key) — only the
 * display name is cleaned. A title that already reads well is never touched.
 */
class NormalizeGameTitlesCommand extends Command
{
    protected $signature = 'games:normalize-titles
        {--dry-run : Show what would change, write nothing}
        {--force-all : Also rewrite titles that already look fine}';

    protected $description = 'Give game templates a clean display title derived from their code';

    public function handle(BundleEntryResolver $resolver): int
    {
        $dry = (bool) $this->option('dry-run');
        $forceAll = (bool) $this->option('force-all');

        $changed = 0;
        $rows = [];

        // NB: no orderBy() here — chunkById() paginates on the primary key, and a
        // secondary sort silently makes its id cursor skip rows.
        GameTemplate::query()->chunkById(300, function ($templates) use ($resolver, $dry, $forceAll, &$changed, &$rows) {
            foreach ($templates as $template) {
                $current = (string) $template->title;
                $pretty = $resolver->prettyName($template->code);

                if ($pretty === '' || $pretty === $current) {
                    continue;
                }
                if (! $forceAll && ! $this->looksRaw($current, $template->code)) {
                    continue;
                }

                $changed++;
                $rows[] = [$template->code, $current ?: '—', $pretty];
                if (! $dry) {
                    $template->update(['title' => $pretty]);
                }
            }
        });

        if ($rows !== []) {
            usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
            $this->table(['Code', 'Old title', $dry ? 'Would become' : 'New title'], $rows);
        }
        $this->newLine();
        $this->info(sprintf('%s %d title(s)%s', $dry ? 'Would update' : 'Updated', $changed, $dry ? '' : '.'));

        return self::SUCCESS;
    }

    /**
     * A title is "raw" when it's empty, equals the code, or still contains a
     * word-internal lower→upper hump ("AncientEgyptClassic", "Just JewelsDX",
     * "Book Of NileLost Chapter") — i.e. a code, or a bad split. A properly
     * cased legacy title ("Queen of Rio", "Santa vs Rudolph") has no such hump
     * and is left alone.
     */
    private function looksRaw(string $title, string $code): bool
    {
        $title = trim($title);

        return $title === ''
            || $title === $code
            || preg_match('/\p{Ll}\p{Lu}/u', $title) === 1;
    }
}
