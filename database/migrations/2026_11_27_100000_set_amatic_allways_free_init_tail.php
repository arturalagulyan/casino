<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * All Ways Candy / All Ways Hottest Fruits read the free-game multiplier from
 * the last tail field. The captured (base-game) init tail ends `0f10` (= 0),
 * so a session resumed inside free spins froze under the bonus intro overlay.
 * Give them a free-spin init tail ending like their spin tail (`0f12` = ×2).
 */
return new class extends Migration
{
    private const CODES = ['AllwaysCandy', 'AllwaysHottestFruits'];

    public function up(): void
    {
        $this->apply(function (array $client): ?array {
            $init = (string) ($client['init_tail'] ?? '');
            $spin = (string) ($client['spin_tail'] ?? '');
            if ($spin !== '0f12' || ! str_ends_with($init, '0f10')) {
                return null;
            }
            $client['free_init_tail'] = substr($init, 0, -4).$spin;

            return $client;
        });
    }

    public function down(): void
    {
        $this->apply(function (array $client): ?array {
            unset($client['free_init_tail']);

            return $client;
        });
    }

    /** @param  callable(array): ?array  $change */
    private function apply(callable $change): void
    {
        foreach (DB::table('game_templates')->whereIn('code', self::CODES)->get(['id', 'bonus_config']) as $tpl) {
            $config = json_decode((string) $tpl->bonus_config, true);
            if (! is_array($config) || ! is_array($config['client'] ?? null)) {
                continue;
            }
            $client = $change($config['client']);
            if ($client === null) {
                continue;
            }
            $config['client'] = $client;
            DB::table('game_templates')->where('id', $tpl->id)->update(['bonus_config' => json_encode($config)]);
        }
    }
};
