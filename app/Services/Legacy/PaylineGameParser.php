<?php

namespace App\Services\Legacy;

/**
 * Parses a modern Pragmatic Play "gs2c" classic-payline game's legacy
 * `init.php` — same flat "key=value" dump shape as {@see TumbleGameParser},
 * different fields (`payline`, `mo_v`/`mo_s`, `msi`, an extended `scatters`
 * carrying free-spin tables, no `prm`/tumble fields at all).
 */
class PaylineGameParser
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
        return (int) ($this->config['sh'] ?? 3);
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
     * Symbol id => payouts, one row per `;`-separated entry, symbol id *is*
     * its position (no separate id field), kept in the legacy's own
     * descending-count order (index 0 = the widest paying run).
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

    /** @return list<list<int>> row-per-reel, row index (0-based) per reel column */
    public function paylines(): array
    {
        $rows = explode(';', $this->config['payline'] ?? '');

        return array_map(
            fn (string $row) => array_map('intval', explode(',', $row)),
            $rows,
        );
    }

    public function lines(): int
    {
        return count($this->paylines());
    }

    public function wildSymbol(): int
    {
        $parts = explode('~', $this->config['wilds'] ?? '2~0');

        return (int) $parts[0];
    }

    public function scatterSymbol(): int
    {
        $parts = explode('~', $this->config['scatters'] ?? '1~0');

        return (int) $parts[0];
    }

    /**
     * The extended `scatters=` field carries FOUR `~`-separated segments here
     * (unlike the tumble family's two): symbol~payTable~fsMaxByCount~fsMulByCount,
     * each table indexed count-descending (index 0 = 5 scatters).
     *
     * @return array{paytable:list<float>, fs:list<int>, fsmul:list<int>}
     */
    public function scatterTables(): array
    {
        $parts = explode('~', $this->config['scatters'] ?? '1~0~0~1');

        return [
            'paytable' => array_map('floatval', explode(',', $parts[1] ?? '0')),
            'fs' => array_map('intval', explode(',', $parts[2] ?? '0')),
            'fsmul' => array_map('intval', explode(',', $parts[3] ?? '1')),
        ];
    }

    /** The "money"/coin symbol — carries a weighted-random cash value, config `mo_s=`. */
    public function moneySymbol(): ?int
    {
        return isset($this->config['mo_s']) ? (int) $this->config['mo_s'] : null;
    }

    /** @return list<int> weighted-random value pool a money symbol draws from, config `mo_v=` */
    public function moneyValues(): array
    {
        return array_map('intval', explode(',', $this->config['mo_v'] ?? '0'));
    }

    /** The mystery-scatter trigger symbol (`doMysteryScatter` swaps a board symbol for a random one in this range), config `msi=`. */
    public function mysteryTriggerSymbol(): ?int
    {
        return isset($this->config['msi']) ? (int) $this->config['msi'] : null;
    }

    /** @return list<float> */
    public function betOptions(): array
    {
        return array_map('floatval', explode(',', $this->config['sc'] ?? '1'));
    }

    public function needAddFreeSpins(): int
    {
        return (int) ($this->config['settings_addfs'] ?? 5);
    }

    public function needFreeSpins(): int
    {
        return (int) ($this->config['settings_needfs'] ?? 3);
    }
}
