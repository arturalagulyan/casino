<?php

namespace App\Services\Legacy;

use App\Services\GamePlay\GameConfig;

/**
 * Parses a modern Pragmatic Play "gs2c" game's legacy `init.php` — a plain
 * PHP file `return`ing a flat list of `"key=value"` strings (its raw
 * doInit-response fields) — into the structures {@see \App\Services\GamePlay\Engine\TumbleEngine}
 * and {@see GameConfig} need.
 *
 * Deliberately NOT a generalisation of {@see EgtGameParser} — this is a
 * different package shape entirely (a flat wire-format dump, not PHP source
 * with named arrays/classes) from a different, much larger game family
 * (~100 titles categorised "Pragmatic" in the legacy DB with no `PM` suffix,
 * each shipping its own copy of a `PragmaticLib` math engine).
 */
class TumbleGameParser
{
    /** @var list<string> the raw "key=value" lines, verbatim — replayed near-as-is for `doInit` */
    private array $raw;

    /** @var array<string,string> the same, split into a lookup */
    private array $config = [];

    public function __construct(string $initPhpPath)
    {
        /** @var list<string> $raw */
        $raw = require $initPhpPath;
        $this->raw = $raw;

        foreach ($this->raw as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $this->config[$key] = $value;
        }
    }

    /** @return list<string> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function rowCount(): int
    {
        return (int) ($this->config['sh'] ?? 5);
    }

    /** @return array<int,list<int>> 0-indexed reel => symbol strip */
    public function reelStrips(int $set): array
    {
        $value = $this->config["reel_set{$set}"] ?? null;
        if ($value === null) {
            return [];
        }

        return array_map(
            fn (string $reel) => array_map('intval', explode(',', $reel)),
            explode('~', $value),
        );
    }

    public function reelCount(): int
    {
        return count($this->reelStrips(0));
    }

    /**
     * Symbol id => payouts, ONE ARRAY PER SYMBOL, indexed by grid position in
     * the `;`-separated list (symbol id *is* its position — no separate id
     * field). Kept in the legacy's own descending-count order (index 0 =
     * the full-grid payout, last index = the smallest paying count) since
     * that's the exact order {@see TumbleEngine} indexes from the end
     * against, matching legacy `WinChecker`.
     *
     * @return array<int,list<float>>
     */
    public function paytable(): array
    {
        $rows = explode(';', $this->config['paytable'] ?? '');
        $out = [];
        foreach ($rows as $i => $row) {
            $out[$i] = array_map('floatval', explode(',', $row));
        }

        return $out;
    }

    /** @return list<int> */
    public function symbols(): array
    {
        return array_keys($this->paytable());
    }

    public function scatterSymbol(): int
    {
        $parts = explode('~', $this->config['scatters'] ?? '1~0');

        return (int) $parts[0];
    }

    /** @return list<float> descending-count order, same convention as {@see self::paytable()} */
    public function scatterPaytable(): array
    {
        $parts = explode('~', $this->config['scatters'] ?? '1~0');

        return array_map('floatval', explode(',', $parts[1] ?? '0'));
    }

    /** @return array{symbol:int,values:list<int>} the multiplier-bomb symbol + its possible values */
    public function multiplier(): array
    {
        $groups = explode(';', $this->config['prm'] ?? '');
        $first = explode('~', $groups[0]);

        return [
            'symbol' => (int) $first[0],
            'values' => array_map('intval', explode(',', $first[1] ?? '2')),
        ];
    }

    /** @return list<float> */
    public function betOptions(): array
    {
        return array_map('floatval', explode(',', $this->config['sc'] ?? '1'));
    }

    public function lines(): int
    {
        return (int) ($this->config['l'] ?? 20);
    }

    public function freeSpinsCount(): int
    {
        return (int) ($this->config['settings_fs'] ?? 10);
    }

    public function needAddFreeSpins(): int
    {
        return (int) ($this->config['settings_needaddfs'] ?? 3);
    }

    public function addFreeSpins(): int
    {
        return (int) ($this->config['settings_addfs'] ?? 5);
    }

    public function totalBetMin(): float
    {
        return (float) ($this->config['total_bet_min'] ?? 0.2);
    }

    public function totalBetMax(): float
    {
        return (float) ($this->config['total_bet_max'] ?? 2000);
    }
}
