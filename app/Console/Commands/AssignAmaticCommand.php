<?php

namespace App\Console\Commands;

use App\Enums\ClientProtocol;
use App\Models\Category;
use App\Models\GameTemplate;
use Illuminate\Console\Command;

/**
 * Marks the Amatic games as speaking the "amarent" WebSocket protocol, and
 * backfills the wild / scatter / free-spin config the generic import missed
 * (Amatic names its symbols `SYM_0..9`; the wild/scatter designation lives in
 * the per-game `Server.php`: `$wild = ['0']; $scatter = '9';`).
 *
 * Detection: `gamess/<code>/amarent/*.html` exists AND
 * `app-games/<code>/Server.php` contains `A/u25`.
 */
class AssignAmaticCommand extends Command
{
    protected $signature = 'games:assign-amatic {--dry-run}';

    protected $description = 'Assign the Amatic protocol + backfill symbol config for *AM games';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $mirror = rtrim((string) config('legacy.app_games_path'), '/');
        $gamess = rtrim((string) config('legacy.gamess_path'), '/');

        $assigned = $backfilled = 0;
        $rows = [];

        GameTemplate::query()->chunkById(300, function ($templates) use ($mirror, $gamess, $dry, &$assigned, &$backfilled, &$rows) {
            foreach ($templates as $t) {
                $bundle = "{$gamess}/{$t->code}/amarent";
                $server = "{$mirror}/{$t->code}/Server.php";
                if (! is_dir($bundle) || ! is_file($server)) {
                    continue;
                }
                $src = (string) file_get_contents($server);
                if (! str_contains($src, 'A/u25')) {
                    continue;
                }

                $patch = [];
                if ($t->client_protocol !== ClientProtocol::Amatic) {
                    $patch['client_protocol'] = ClientProtocol::Amatic;
                    $assigned++;
                }

                $wild = $this->firstInt($src, "/\\\$wild\s*=\s*\[\s*'(\d+)'/");
                $scatter = $this->firstInt($src, "/\\\$scatter\s*=\s*'(\d+)'/");
                $settings = @file_get_contents("{$mirror}/{$t->code}/SlotSettings.php") ?: '';
                $freeCount = $this->firstInt($settings, '/slotFreeCount\s*=\s*(\d+)/');
                $freeMpl = $this->firstInt($settings, '/slotFreeMpl\s*=\s*(\d+)/');

                foreach ([
                    'wild_symbol' => $wild,
                    'scatter_symbol' => $scatter,
                    'free_spins_count' => $freeCount,
                    'free_spins_multiplier' => $freeMpl,
                ] as $col => $value) {
                    if ($value !== null && ($t->{$col} === null || $t->{$col} === 0)) {
                        $patch[$col] = $value;
                    }
                }

                if ($patch === []) {
                    continue;
                }
                $extra = array_keys(array_diff_key($patch, ['client_protocol' => 1]));
                $rows[] = [$t->code, isset($patch['client_protocol']) ? 'amatic' : '—', implode(',', $extra) ?: '—'];
                if ($extra !== []) {
                    $backfilled++;
                }
                if (! $dry) {
                    $t->forceFill($patch)->save();
                }
            }
        });

        // Belt-and-suspenders: the Amatic category also carries the protocol, so
        // a game added to it later inherits it without a re-run.
        $cat = Category::query()->where('slug', 'amatic')->first();
        if ($cat && data_get($cat->config, 'client_protocol') !== 'amatic') {
            $rows[] = ['(category: amatic)', 'amatic', '—'];
            if (! $dry) {
                $cat->update(['config' => array_merge((array) $cat->config, ['client_protocol' => 'amatic'])]);
            }
        }

        if ($rows !== []) {
            $this->table(['Code', 'protocol', 'backfilled cols'], $rows);
        }
        $this->newLine();
        $this->info(sprintf('%s  protocol set on %d  ·  config backfilled on %d', $dry ? 'Dry run —' : 'Done.', $assigned, $backfilled));

        return self::SUCCESS;
    }

    private function firstInt(string $haystack, string $pattern): ?int
    {
        return preg_match($pattern, $haystack, $m) ? (int) $m[1] : null;
    }
}
