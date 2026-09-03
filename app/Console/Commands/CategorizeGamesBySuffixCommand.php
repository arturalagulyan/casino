<?php

namespace App\Console\Commands;

use App\Enums\ClientProtocol;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use Illuminate\Console\Command;

/**
 * Backfills the provider category for games that have none, from their code
 * suffix (`…EGT` → Egt, `…CT` → C-Technology, …). Only touches games with zero
 * categories; game *templates* inherit the result through their games (see
 * {@see GameTemplate::getCategoriesAttribute()}), so filling the
 * games fills the templates too.
 *
 * The map was derived from the games that already carry a category — each
 * suffix's dominant one.
 */
class CategorizeGamesBySuffixCommand extends Command
{
    protected $signature = 'games:categorize-by-suffix {--dry-run}';

    protected $description = 'Attach the provider category to un-categorised games from their code suffix';

    /** trailing code token (longest first) => category slug */
    private const array SUFFIX_MAP = [
        'XXLRHFPGM' => 'gamomat',
        'RHFPGM' => 'gamomat',
        'XXLGM' => 'gamomat',
        'IIEGT' => 'egt',
        'IIAT' => 'aristocrat',
        'HDMN' => 'mainama',
        'OZMN' => 'mainama',
        'DXGT' => 'greentube',
        'JPPT' => 'playtech',
        'MHPT' => 'playtech',
        'JPSW' => 'skywind',
        'QSW' => 'skywind',
        'CQ9' => 'cq9',
        'XAM' => 'amatic',
        'PGD' => 'gdgames',
        'PGT' => 'playgt',
        'ISB' => 'isoftbet',
        'NET' => 'netent',
        'EGT' => 'egt',
        'AM' => 'amatic',
        'AT' => 'aristocrat',
        'BS' => 'betsoft',
        'CT' => 'casino-technology',
        'CL' => 'greentube',
        'DX' => 'greentube',
        'GT' => 'greentube',
        'GM' => 'gamomat',
        'GV' => 'keno',
        'IG' => 'igrosoft',
        'KA' => 'ka-gaming',
        'MN' => 'mainama',
        'NG' => 'netgame',
        'PG' => 'playngo',
        'PM' => 'pragmatic',
        'PT' => 'playtech',
        'SW' => 'skywind',
        'VP' => 'arcade',
        'VS' => 'vision',
        'WD' => 'wazdan',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $catIdBySlug = Category::query()->pluck('id', 'slug');
        $missing = collect(self::SUFFIX_MAP)->unique()->reject(fn ($slug) => $catIdBySlug->has($slug));
        if ($missing->isNotEmpty()) {
            $this->error('Missing categories: '.$missing->implode(', '));

            return self::FAILURE;
        }

        $counts = [];
        $skipped = [];
        $attached = 0;

        Game::query()
            ->doesntHave('categories')
            ->with('template:id,code,client_protocol')
            ->chunkById(500, function ($games) use ($dry, $catIdBySlug, &$counts, &$skipped, &$attached) {
                foreach ($games as $game) {
                    $code = (string) $game->template->code;
                    $slug = $this->slugFor($code, $game->template->client_protocol);

                    if ($slug === null) {
                        $skipped[$code] = true;

                        continue;
                    }

                    if (! $dry) {
                        $game->categories()->syncWithoutDetaching([$catIdBySlug[$slug]]);
                    }
                    $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                    $attached++;
                }
            });

        ksort($counts);
        $this->table(
            ['Category', 'Games attached'],
            collect($counts)->map(fn ($n, $slug) => [$slug, $n])->values()->all(),
        );

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(count($skipped).' un-categorised games left untouched (no confident suffix): '
                .collect(array_keys($skipped))->take(20)->implode(', ').(count($skipped) > 20 ? ' …' : ''));
        }

        $this->newLine();
        $this->info(sprintf('%s  %d games categorised across %d providers.', $dry ? 'Dry run —' : 'Done.', $attached, count($counts)));

        return self::SUCCESS;
    }

    private function slugFor(string $code, ?ClientProtocol $protocol): ?string
    {
        if (preg_match('/([A-Z]{2,}[0-9]*)$/', $code, $m)) {
            $token = $m[1];

            // Exact token, else the longest known suffix it ends with (so a
            // compound like `SwampLandHDMN` → `HDMN`, and `…MN` still resolves).
            return self::SUFFIX_MAP[$token] ?? collect(self::SUFFIX_MAP)
                ->first(fn ($slug, $suffix) => str_ends_with($token, $suffix));
        }

        // No provider suffix — the legacy Novomatic games ship as bare names and
        // speak the `slotEvent` protocol.
        return $protocol === ClientProtocol::SlotEvent ? 'novomatic' : null;
    }
}
