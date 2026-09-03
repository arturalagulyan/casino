<?php

namespace App\Console\Commands;

use App\Enums\ClientProtocol;
use App\Models\GameTemplate;
use App\Services\Legacy\LegacyGameReader;
use Illuminate\Console\Command;

/**
 * Marks the Novomatic / Greentube games as speaking the legacy `slotEvent` HTTP
 * protocol, and backfills the wild/scatter/feature config the generic
 * `LegacyGameSpec` import missed (Novomatic's wild is the first paytable symbol
 * `P_1`, never named "WILD"; the free-spin table is `slotScatterFreeCount`).
 *
 * Detection: `app-games/<code>/Server.php` contains `slotEvent` AND
 * `gamess/<code>/js/loader.js` exists (the shared front-end engine).
 */
class AssignSlotEventCommand extends Command
{
    protected $signature = 'games:assign-slot-event {--dry-run}';

    protected $description = 'Assign the slotEvent protocol + backfill symbol config for Novomatic/Greentube games';

    public function handle(LegacyGameReader $legacy): int
    {
        $dry = (bool) $this->option('dry-run');
        $assigned = $backfilled = 0;
        $rows = [];

        // NB: no orderBy() — chunkById() pages on `id`; ordering by another
        // column makes the keyset cursor skip rows (only ~1/3 get visited).
        GameTemplate::query()->chunkById(300, function ($templates) use ($legacy, $dry, &$assigned, &$backfilled, &$rows) {
            foreach ($templates as $t) {
                $dir = $legacy->bundleDir($t->code);
                // The shared Novomatic/Greentube engine: js/loader.js + js/core.js
                // + a config/{engine,desktop_view}.json. NetGame/others also carry
                // a `slotEvent` Server.php but a different (js/core.js-less) engine.
                $isNovomaticEngine = is_file("{$dir}/js/loader.js") && is_file("{$dir}/js/core.js")
                    && (is_file("{$dir}/config/engine.json") || is_file("{$dir}/config/desktop_view.json"));

                if (! $isNovomaticEngine || ! $legacy->usesSlotEvent($t->code)) {
                    continue;
                }

                $raw = $legacy->slotSettings($t->code);
                $names = $raw['symbol_names'] ?? [];
                $indexOf = fn (?string $n) => $n !== null && ($i = array_search($n, $names, true)) !== false ? (int) $i : null;

                $patch = [];
                if ($t->client_protocol !== ClientProtocol::SlotEvent) {
                    $patch['client_protocol'] = ClientProtocol::SlotEvent;
                    $assigned++;
                }
                foreach ([
                    'wild_symbol' => $indexOf($raw['wild'] ?? null),
                    'scatter_symbol' => $indexOf($raw['scatter'] ?? null),
                    'wild_multiplier' => $raw['slot_wild_mpl'] ?? null,
                    'free_spins_multiplier' => $raw['slot_free_mpl'] ?? null,
                    'free_spins_count' => $raw['slot_free_count'] ?? null,
                    'free_spins_table' => $raw['slot_scatter_free_count'] ?? null,
                    'gamble_type' => $raw['gamble_type'] ?? null,
                ] as $col => $value) {
                    if ($value !== null && ($t->{$col} === null || $t->{$col} === 0 || $t->{$col} === [])) {
                        $patch[$col] = $value;
                    }
                }

                if ($patch === []) {
                    continue;
                }
                $rows[] = [$t->code, isset($patch['client_protocol']) ? 'slot_event' : '—', implode(',', array_keys(array_diff_key($patch, ['client_protocol' => 1]))) ?: '—'];
                if (array_diff_key($patch, ['client_protocol' => 1]) !== []) {
                    $backfilled++;
                }
                if (! $dry) {
                    $t->forceFill($patch)->save();
                }
            }
        });

        if ($rows !== []) {
            $this->table(['Code', 'protocol', 'backfilled cols'], $rows);
        }
        $this->newLine();
        $this->info(sprintf('%s  protocol set on %d  ·  config backfilled on %d', $dry ? 'Dry run —' : 'Done.', $assigned, $backfilled));

        return self::SUCCESS;
    }
}
