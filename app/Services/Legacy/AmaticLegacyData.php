<?php

namespace App\Services\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * Per-game tuning the legacy Amatic backend kept in its `games` table rather
 * than in the game package: the bet-per-line ladder (`games.bet`, read at
 * runtime via `SlotSettings::$Bet = explode(',', $game->bet)`), the win-chance
 * tables, reserve %, visibility and the shop RTP %.
 *
 * Reads the `legacy` DB connection when it's reachable, and falls back to the
 * exported `database/seeders/data/amatic-win-chances.json` otherwise (e.g. on
 * the deploy server). Shared by `amatic:import` and `amatic:fetch`.
 */
class AmaticLegacyData
{
    /** Legacy shop ids whose `games` rows carry the per-game tuning, most authoritative first. */
    private const array SHOP_IDS = [13, 14, 0];

    private ?bool $reachable = null;

    /** @var array<string, object|null> */
    private array $rowCache = [];

    /** @var array<int, int|null> */
    private array $shopPercentCache = [];

    /** @var array<string, array>|null */
    private ?array $bundled = null;

    public function reachable(): bool
    {
        if ($this->reachable === null) {
            try {
                DB::connection('legacy')->table('games')->limit(1)->get();
                $this->reachable = true;
            } catch (\Throwable) {
                $this->reachable = false;
            }
        }

        return $this->reachable;
    }

    /**
     * The game's bet-per-line ladder: legacy DB first, then the bundled export.
     *
     * @return list<float>|null
     */
    public function betOptions(string $code): ?array
    {
        $row = $this->reachable() ? $this->firstRow($code) : null;

        return ($row ? $this->parseBetList($row->bet ?? null) : null) ?? $this->bundledData($code)['bets'] ?? null;
    }

    /**
     * Spin/bonus win-chance tables: legacy DB first, then the bundled export.
     *
     * @return array{spin: array, bonus: array}|null
     */
    public function winChances(string $code): ?array
    {
        return ($this->reachable() ? $this->dbWinChances($code) : null) ?? $this->bundledWinChances($code);
    }

    public function gameRow(string $code, int $shopId): ?object
    {
        $key = $code.'@'.$shopId;
        if (! array_key_exists($key, $this->rowCache)) {
            try {
                $this->rowCache[$key] = DB::connection('legacy')->table('games')
                    ->where('name', $code)->where('shop_id', $shopId)->first();
            } catch (\Throwable) {
                $this->rowCache[$key] = null;
            }
        }

        return $this->rowCache[$key];
    }

    public function shopPercent(int $shopId): ?int
    {
        if (! array_key_exists($shopId, $this->shopPercentCache)) {
            try {
                $v = DB::connection('legacy')->table('shops')->where('id', $shopId)->value('percent');
                $this->shopPercentCache[$shopId] = $v !== null ? (int) $v : null;
            } catch (\Throwable) {
                $this->shopPercentCache[$shopId] = null;
            }
        }

        return $this->shopPercentCache[$shopId];
    }

    /** @return list<float>|null */
    public function parseBetList(?string $csv): ?array
    {
        if (! $csv) {
            return null;
        }

        $bets = array_values(array_filter(array_map(
            'floatval',
            array_filter(array_map('trim', explode(',', $csv)), fn ($v) => $v !== '' && is_numeric($v)),
        ), fn ($v) => $v > 0));

        return $bets ?: null;
    }

    private function firstRow(string $code): ?object
    {
        foreach (self::SHOP_IDS as $shopId) {
            if ($row = $this->gameRow($code, $shopId)) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{spin: array, bonus: array}|null */
    private function dbWinChances(string $code): ?array
    {
        $row = $this->firstRow($code);
        if (! $row) {
            return null;
        }

        $spin = json_decode((string) ($row->lines_percent_config_spin ?? ''), true);
        $bonus = json_decode((string) ($row->lines_percent_config_bonus ?? ''), true);

        if (! is_array($spin) || ! is_array($bonus)) {
            return null;
        }

        $toInt = fn ($t) => collect($t)->map(fn ($bands) => collect($bands)->map(fn ($v) => (int) $v)->all())->all();

        return ['spin' => $toInt($spin), 'bonus' => $toInt($bonus)];
    }

    /**
     * Win-chance tables + bet ladders exported from the legacy `games` table
     * (`database/seeders/data/amatic-win-chances.json`).
     *
     * @return array{spin?: array, bonus?: array, bets?: list<float>}
     */
    private function bundledData(string $code): array
    {
        if ($this->bundled === null) {
            $path = database_path('seeders/data/amatic-win-chances.json');
            $this->bundled = is_file($path)
                ? (array) json_decode((string) file_get_contents($path), true)
                : [];
        }

        return (array) ($this->bundled[$code] ?? []);
    }

    /** @return array{spin: array, bonus: array}|null */
    private function bundledWinChances(string $code): ?array
    {
        $row = $this->bundledData($code);

        return isset($row['spin'], $row['bonus']) ? ['spin' => $row['spin'], 'bonus' => $row['bonus']] : null;
    }
}
