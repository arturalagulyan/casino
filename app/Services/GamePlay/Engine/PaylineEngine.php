<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\Protocol\PragmaticPaylineFormatter;

/**
 * Classic-payline engine for the modern Pragmatic Play "gs2c" family that
 * ISN'T scatter-pays/tumble (see {@see TumbleEngine} for that branch) —
 * AztecKing-shape titles: 5x3 grid, left-to-right paylines with wild
 * substitution, a scatter that triggers free spins (count -> fs/fsmul
 * tables), and a "money" symbol that carries a weighted-random cash value
 * summed into the win every spin. Ported from the legacy
 * `VanguardLTE\Games\AztecKing\PragmaticLib` classes (SlotArea / WinChecker /
 * FreeSpin / LogAndServer).
 *
 * Unlike {@see TumbleEngine}, there is no cascade — legacy's own respin
 * branch for this family is dead code (`if (false && $log …)`). Each
 * `doSpin` is one independent board draw; free spins are just a persisted
 * counter (`fs`/`fsmax`).
 *
 * Not ported (per-title extras, same class of gap as every other provider's
 * bespoke bonus mechanics): the "collector" symbol overlay that can expand
 * or force extra money symbols onto the board (legacy `msi`/`msr`/`ep`/`stf`),
 * and the "ea"/"ma" money-symbol variants (hardcoded extra symbol ids in
 * some titles) — only the plain weighted-value money symbol is evaluated.
 */
class PaylineEngine
{
    /**
     * @return array{slotArea: list<int>, symbolsAfter: list<int>, symbolsBelow: list<int>}
     */
    public function freshBoard(GameConfig $cfg, bool $bonus): array
    {
        $drawn = $this->drawWindow($cfg, $bonus);

        return [
            'slotArea' => $this->flatten($drawn['reels'], $cfg->reelCount(), $cfg->rowCount()),
            'symbolsAfter' => $drawn['symbolsAfter'],
            'symbolsBelow' => $drawn['symbolsBelow'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $prevState  the previous spin's persisted state (null = fresh round)
     * @return array<string,mixed> the new state to persist (also the shape {@see PragmaticPaylineFormatter} reads from)
     */
    public function step(GameConfig $cfg, ?array $prevState, float $betline, int $lines): array
    {
        $cfgP = $cfg->paylineConfig();
        $inFreeSpins = $prevState !== null && ($prevState['FreeState'] ?? null) === 'FreeSpin';

        $board = $this->freshBoard($cfg, $inFreeSpins);
        $slotArea = $board['slotArea'];

        $lineResult = $this->evaluateLines($cfg, $slotArea, $betline);
        $coinResult = $this->evaluateCoins($cfgP, $slotArea, $betline);

        $total = $lineResult['total'] + $coinResult['total'];

        $scatter = $cfg->scatterSymbol();
        $scatterCount = $scatter !== null ? count(array_keys($slotArea, $scatter, true)) : 0;

        return $this->nextState($prevState, $board, $lineResult, $coinResult, $total, $scatterCount, $cfgP, $betline, $lines);
    }

    /**
     * The `doMysteryScatter` action — a client-initiated, ungated free-spins
     * grant (legacy `DoMysteryScatter::doMystery`, ported as-is: it really
     * doesn't check anything server-side beyond picking which symbol id
     * becomes "mystery" for the client's own animation).
     *
     * @return array<string,mixed>
     */
    public function mysteryScatter(GameConfig $cfg): array
    {
        $cfgP = $cfg->paylineConfig();
        $ms = random_int($cfgP['mystery_symbol_min'], $cfgP['mystery_symbol_max']);

        return [
            'FreeState' => 'FreeSpin',
            'fs' => 1,
            'fsmax' => 10,
            'fsmul' => 1,
            'fswin' => 0.0,
            'MysterySymbol' => $ms,
        ];
    }

    // ---- board -----------------------------------------------------

    /** @return array{reels: array<int,list<int>>, symbolsAfter: list<int>, symbolsBelow: list<int>} */
    private function drawWindow(GameConfig $cfg, bool $bonus): array
    {
        $rows = $cfg->rowCount();
        $reels = [];
        $symbolsAfter = [];
        $symbolsBelow = [];

        foreach ($cfg->reelStrips($bonus) as $reelIdx => $strip) {
            $n = max(1, count($strip));
            $pos = random_int(0, $n - 1);

            $window = [];
            for ($r = 0; $r < $rows; $r++) {
                $window[] = $strip[($pos + $r) % $n];
            }

            $reels[$reelIdx] = $window;
            $symbolsAfter[$reelIdx] = $strip[($pos - 1 + $n) % $n];
            $symbolsBelow[$reelIdx] = $window[$rows - 1];
        }

        return ['reels' => $reels, 'symbolsAfter' => $symbolsAfter, 'symbolsBelow' => $symbolsBelow];
    }

    /** @param  array<int,list<int>>  $reels */
    private function flatten(array $reels, int $reelCount, int $rows): array
    {
        $flat = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($reel = 0; $reel < $reelCount; $reel++) {
                $flat[] = $reels[$reel][$row];
            }
        }

        return $flat;
    }

    // ---- win evaluation ----------------------------------------------

    /**
     * Left-to-right payline matching with wild substitution, count-indexed-
     * from-the-end paytable (legacy `WinChecker::getWin`'s payline loop).
     *
     * @return array{total: float, lines: list<array{symbol:int,count:int,pay:float,positions:list<int>,line:int}>}
     */
    private function evaluateLines(GameConfig $cfg, array $slotArea, float $betline): array
    {
        $reelCount = $cfg->reelCount();
        $paytable = $cfg->paytable();
        $wild = $cfg->wildSymbol();

        $total = 0.0;
        $lines = [];

        foreach ($cfg->paylines() as $index => $payline) {
            $line = [];
            foreach ($payline as $reel => $row) {
                $line[] = $slotArea[$row * $reelCount + $reel];
            }

            $count = 1;
            $winSymbol = $line[0];
            foreach ($line as $col => $value) {
                if ($col === 0) {
                    continue;
                }
                if ($value !== $wild && $winSymbol === $wild) {
                    $winSymbol = $value;
                }
                if ($winSymbol !== $wild && $winSymbol !== $value && $value !== $wild) {
                    break;
                }
                $count++;
            }

            $row = $paytable[$winSymbol] ?? [];
            if ($row === []) {
                continue;
            }
            $pay = round(($row[count($row) - $count] ?? 0.0) * $betline, 2);
            if ($pay <= 0) {
                continue;
            }

            $positions = [];
            for ($col = 0; $col < $count; $col++) {
                $positions[] = $payline[$col] * $reelCount + $col;
            }

            $lines[] = ['symbol' => $winSymbol, 'count' => $count, 'pay' => $pay, 'positions' => $positions, 'line' => $index];
            $total += $pay;
        }

        return ['total' => $total, 'lines' => $lines];
    }

    /**
     * The "money" symbol: every occurrence carries an independently-drawn
     * weighted-random cash value (legacy `SlotArea::getMO`'s `mo`/`mo_t`
     * arrays, "v" entries only — see class docblock for what's not ported).
     *
     * @return array{total: float, positions: list<int>, values: list<int>}
     */
    private function evaluateCoins(array $cfgP, array $slotArea, float $betline): array
    {
        $symbol = $cfgP['money_symbol'];
        if ($symbol === null) {
            return ['total' => 0.0, 'positions' => [], 'values' => []];
        }

        $positions = array_keys($slotArea, $symbol, true);
        if ($positions === []) {
            return ['total' => 0.0, 'positions' => [], 'values' => []];
        }

        $pool = $cfgP['money_values'];
        $values = [];
        $sum = 0;
        foreach ($positions as $position) {
            $value = $pool[$this->weightedIndex(count($pool))];
            $values[] = $value;
            $sum += $value;
        }

        return ['total' => round($sum * $betline, 2), 'positions' => $positions, 'values' => $values];
    }

    /** Geometric-decay weighted pick, index 0 (the smallest configured value) most likely. */
    private function weightedIndex(int $poolSize): int
    {
        $i = 0;
        while ($i < $poolSize - 1 && random_int(0, 99) < 48) {
            $i++;
        }

        return $i;
    }

    // ---- state machine (legacy LogAndServer::getResult, scoped) -----

    /**
     * @param  array<string,mixed>|null  $prevState
     * @return array<string,mixed>
     */
    private function nextState(
        ?array $prevState,
        array $board,
        array $lineResult,
        array $coinResult,
        float $total,
        int $scatterCount,
        array $cfgP,
        float $betline,
        int $lines,
    ): array {
        $inFreeSpins = $prevState !== null && ($prevState['FreeState'] ?? null) === 'FreeSpin';

        $state = [
            'SlotArea' => $board['slotArea'],
            'SymbolsAfter' => $board['symbolsAfter'],
            'SymbolsBelow' => $board['symbolsBelow'],
            'Lines' => $lineResult['lines'],
            'Coins' => $coinResult,
            'Win' => $total,
            'TotalWin' => $total,
            'LastBet' => $betline,
            'LastLines' => $lines,
        ];

        if ($inFreeSpins) {
            $fsmul = (int) ($prevState['fsmul'] ?? 1);
            $fsmax = (int) $prevState['fsmax'];
            $fsNumber = (int) $prevState['fs'];
            $fswin = (float) $prevState['fswin'] + $total * $fsmul;
            $prevTotal = (float) ($prevState['TotalWin'] ?? 0.0);

            if ($scatterCount >= $cfgP['needaddfs']) {
                $fsmax += $cfgP['addfs'];
            }

            $state['Win'] = $total * $fsmul;
            $state['TotalWin'] = $prevTotal + $state['Win'];

            if ($fsNumber >= $fsmax) {
                $state['FreeState'] = 'LastFreeSpin';
                $state['fs_total'] = $fsNumber;
                $state['fswin_total'] = $fswin;
                $state['fsmul_total'] = $fsmul;
            } else {
                $state['FreeState'] = 'FreeSpin';
                $state['fs'] = $fsNumber + 1;
                $state['fsmax'] = $fsmax;
                $state['fsmul'] = $fsmul;
                $state['fswin'] = $fswin;
            }
        } else {
            // Fresh spin — scatter count decides a free-spins trigger.
            $fsGrant = $scatterCount >= 1 && $scatterCount <= 5
                ? $cfgP['fs_by_scatter_count'][5 - $scatterCount] ?? 0
                : 0;

            if ($fsGrant > 0) {
                $fsmul = $cfgP['fsmul_by_scatter_count'][5 - $scatterCount] ?? 1;
                $state['FreeState'] = 'FreeSpin';
                $state['fs'] = 1;
                $state['fsmax'] = $fsGrant;
                $state['fsmul'] = $fsmul;
                $state['fswin'] = 0.0;
                $state['TriggerScatterCount'] = $scatterCount;
            }
        }

        return $state;
    }
}
