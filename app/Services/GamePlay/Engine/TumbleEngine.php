<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\Protocol\PragmaticTumbleFormatter;

/**
 * Scatter-pays + tumble/cascade engine for the modern Pragmatic Play "gs2c"
 * game family (Sweet Bonanza, Fruit Party, Gates of Olympus, …) — ported
 * faithfully from the legacy `VanguardLTE\Games\BonanzaGold\PragmaticLib`
 * classes (SlotArea / WinChecker / FreeSpin / Multiple / LogAndServer), which
 * is itself the exact server the real client bundle was written against.
 *
 * Unlike the classic {@see SlotEngine} (payline-based, decider-gated to hit a
 * target RTP), this plays the *real* weighted reel strips directly — no
 * artificial win/lose gate; hit frequency and RTP come from the strip design,
 * same as the genuine game. A "step" here is one HTTP `doSpin` call, which is
 * either a fresh board draw (a new bet) or a tumble continuation of the same
 * visual round (bet already taken, winning symbols already cleared
 * client-side, server drops fresh symbols in from the top and re-checks).
 *
 * The grid is stored flat, row-major (`row * reelCount + reel`) throughout —
 * matching the wire protocol's own position numbering (verified against
 * legacy `Multiple::getBonanzaMultiple`'s position formula) — so win/scatter/
 * multiplier positions need no translation before going on the wire.
 */
class TumbleEngine
{
    /**
     * A bare fresh board draw with no win-checking — the cosmetic board
     * `doInit` shows before the player's first real spin.
     *
     * @return array{slotArea: list<int>, symbolsAfter: list<int>, symbolsBelow: list<int>}
     */
    public function freshBoard(GameConfig $cfg): array
    {
        $drawn = $this->drawWindow($cfg, false);

        return [
            'slotArea' => $this->flatten($drawn['reels'], $cfg->reelCount(), $cfg->rowCount()),
            'symbolsAfter' => $drawn['symbolsAfter'],
            'symbolsBelow' => $drawn['symbolsBelow'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $prevState  the previous step's persisted state (null = fresh round)
     * @return array<string,mixed> the new state to persist (also the shape {@see PragmaticTumbleFormatter} reads from)
     */
    public function step(GameConfig $cfg, ?array $prevState, float $betline): array
    {
        $rows = $cfg->rowCount();
        $reels = $cfg->reelCount();
        $tumbleCfg = $cfg->tumbleConfig();

        $state = (string) ($prevState['State'] ?? 'Spin');
        $continuing = in_array($state, ['Respin', 'FirstRespin'], true);
        $inFreeSpins = $prevState !== null
            && array_key_exists('FreeSpinNumber', $prevState)
            && ($prevState['FreeState'] ?? null) !== 'LastFreeSpin';

        $drawn = $this->drawWindow($cfg, $inFreeSpins);

        $slotArea = $continuing
            ? $this->continueBoard($prevState, $drawn, $reels, $rows)
            : $this->flatten($drawn['reels'], $reels, $rows);

        $win = $this->checkWin($cfg, $slotArea, $betline);

        $freeSpins = null;
        if ($win['total'] === 0.0 && ! $inFreeSpins) {
            $freeSpins = $this->checkFreeSpins($cfg, $tumbleCfg, $slotArea, $prevState, $betline);
        }

        $multipliers = null;
        if ($inFreeSpins) {
            $multipliers = $this->checkMultipliers($cfg, $tumbleCfg, $slotArea, $prevState);
        }

        return $this->nextState(
            $prevState, $slotArea, $drawn, $win, $freeSpins, $multipliers, $betline, $tumbleCfg,
        );
    }

    // ---- board -----------------------------------------------------

    /**
     * @return array{reels: array<int,list<int>>, symbolsAfter: list<int>, symbolsBelow: list<int>}
     */
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

    /** @param  array<int,list<int>>  $reels  reelIndex => list of $rows symbols, top to bottom */
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

    /** @return array<int,list<int>> reelIndex => list of $rows symbols, top to bottom */
    private function unflatten(array $flat, int $reelCount, int $rows): array
    {
        $reels = array_fill(0, $reelCount, []);
        foreach ($flat as $i => $v) {
            $row = intdiv($i, $reelCount);
            $reel = $i % $reelCount;
            $reels[$reel][$row] = $v;
        }

        return $reels;
    }

    /**
     * Remove every cell holding a symbol that just paid, keep the rest, and
     * drop fresh symbols in from the top (legacy `SlotArea`'s respin branch).
     * `$drawn` supplies the fresh fill-in symbols — a completely new random
     * window is drawn every step, continuation or not.
     */
    private function continueBoard(array $prevState, array $drawn, int $reelCount, int $rows): array
    {
        $prevFlat = $prevState['SlotArea'];
        $winSymbols = array_unique(array_map(fn ($w) => $w['symbol'], $prevState['WinLines'] ?? []));
        $prevReels = $this->unflatten($prevFlat, $reelCount, $rows);

        $merged = [];
        foreach ($prevReels as $reelIdx => $col) {
            $kept = array_values(array_filter($col, fn ($v) => ! in_array($v, $winSymbols, true)));
            $shortfall = $rows - count($kept);
            $merged[$reelIdx] = $shortfall > 0
                ? array_merge(array_slice($drawn['reels'][$reelIdx], -$shortfall), $kept)
                : $kept;
        }

        return $this->flatten($merged, $reelCount, $rows);
    }

    // ---- win / scatter / multiplier ---------------------------------

    /**
     * Scatter-pays: count every symbol anywhere on the grid, pay from the
     * paytable's count-indexed tail (legacy `WinChecker::getWin`).
     *
     * @return array{total: float, lines: list<array{symbol:int,count:int,pay:float,positions:list<int>}>}
     */
    private function checkWin(GameConfig $cfg, array $slotArea, float $betline): array
    {
        $paytable = $cfg->paytable();
        $counts = array_count_values($slotArea);

        $total = 0.0;
        $lines = [];
        foreach ($counts as $symbol => $count) {
            $row = $paytable[$symbol] ?? [];
            if ($row === []) {
                continue;
            }
            $pay = round(($row[count($row) - $count] ?? 0.0) * $betline, 2);
            if ($pay > 0) {
                $lines[] = [
                    'symbol' => $symbol,
                    'count' => $count,
                    'pay' => $pay,
                    'positions' => array_keys($slotArea, $symbol, true),
                ];
                $total += $pay;
            }
        }

        return ['total' => $total, 'lines' => $lines];
    }

    /**
     * @return array{free_spins:int,pay:float,scatter:int,positions:list<int>}|array{add_free_spins:int}|null
     */
    private function checkFreeSpins(GameConfig $cfg, array $tumbleCfg, array $slotArea, ?array $prevState, float $betline): ?array
    {
        $scatter = $cfg->scatterSymbol();
        if ($scatter === null) {
            return null;
        }

        $counts = array_count_values($slotArea);
        $scatterCount = $counts[$scatter] ?? 0;
        if ($scatterCount === 0) {
            return null;
        }

        $alreadyInFreeSpins = $prevState !== null
            && array_key_exists('FreeSpinNumber', $prevState)
            && ($prevState['FreeState'] ?? null) !== 'LastFreeSpin';

        if ($alreadyInFreeSpins) {
            return $scatterCount >= $tumbleCfg['needaddfs']
                ? ['add_free_spins' => $tumbleCfg['addfs']]
                : null;
        }

        // The scatter's OWN count-indexed-from-the-end payout table — its row
        // in the main `paytable` is always zero (scatter-pays never pays the
        // scatter itself; this trigger payout is a separate bonus on top).
        $scatterPay = $tumbleCfg['scatter_paytable'];
        $pay = round(($scatterPay[count($scatterPay) - $scatterCount] ?? 0.0) * $betline, 2);

        if ($pay <= 0) {
            return null;
        }

        return [
            'free_spins' => $tumbleCfg['free_spins'],
            'pay' => $pay,
            'scatter' => $scatter,
            'positions' => array_keys($slotArea, $scatter, true),
        ];
    }

    /**
     * Multiplier-bomb symbols during free spins — each carries its own random
     * (or, once shown, sticky-for-the-round) multiplier value; the sum
     * applies to the round's total win once the last tumble resolves
     * (legacy `Multiple::getBonanzaMultiple`).
     *
     * @return list<array{symbol:int,position:int,multiplier:int}>|null
     */
    private function checkMultipliers(GameConfig $cfg, array $tumbleCfg, array $slotArea, ?array $prevState): ?array
    {
        $symbol = $tumbleCfg['multiplier_symbol'];
        if ($symbol === null) {
            return null;
        }

        $positions = array_keys($slotArea, $symbol, true);
        if ($positions === []) {
            return null;
        }

        $sticky = [];
        foreach ((array) ($prevState['Multipliers'] ?? []) as $m) {
            $sticky[$m['position']] = $m['multiplier'];
        }

        $out = [];
        foreach ($positions as $position) {
            $out[] = [
                'symbol' => $symbol,
                'position' => $position,
                'multiplier' => $sticky[$position] ?? $tumbleCfg['multiplier_values'][array_rand($tumbleCfg['multiplier_values'])],
            ];
        }

        return $out;
    }

    // ---- state machine (legacy LogAndServer::getResult) -------------

    /**
     * @return array<string,mixed> the new persisted state — everything the
     *                             formatter and the next call's step() need
     */
    private function nextState(
        ?array $prevState,
        array $slotArea,
        array $drawn,
        array $win,
        ?array $freeSpins,
        ?array $multipliers,
        float $betline,
        array $tumbleCfg,
    ): array {
        $prevWasRespin = in_array($prevState['State'] ?? null, ['Respin', 'FirstRespin'], true);

        $state = [
            'SlotArea' => $slotArea,
            'SymbolsAfter' => $drawn['symbolsAfter'],
            'SymbolsBelow' => $drawn['symbolsBelow'],
            'Win' => $win['total'],
            'WinLines' => $win['lines'],
        ];

        if ($win['total'] > 0.0) {
            $state['Respin'] = $prevWasRespin ? (int) $prevState['Respin'] + 1 : 0;
            $state['RespinWin'] = ($prevWasRespin ? (float) $prevState['RespinWin'] : 0.0) + $win['total'];
            $state['TotalWin'] = ($prevWasRespin ? (float) $prevState['TotalWin'] : 0.0) + $win['total'];
            $state['State'] = $prevWasRespin ? 'Respin' : 'FirstRespin';
        } else {
            $state['Respin'] = $prevWasRespin ? (int) $prevState['Respin'] : 0;
            $state['RespinWin'] = $prevWasRespin ? (float) $prevState['RespinWin'] : 0.0;
            $state['TotalWin'] = $prevWasRespin ? (float) $prevState['TotalWin'] : 0.0;
            $state['State'] = $prevWasRespin ? 'LastRespin' : 'Spin';
        }

        // Free spins bookkeeping — carried over from the previous step, or
        // freshly opened by this step's scatter trigger.
        if ($prevState !== null && array_key_exists('FreeSpinNumber', $prevState) && ($prevState['FreeState'] ?? null) !== 'LastFreeSpin') {
            $state['FreeSpins'] = $prevState['FreeSpins'];
            // A free spin's own tumble sequence has fully resolved (State
            // Spin/LastRespin) → advance to the next free spin. Still mid-tumble
            // (Respin/FirstRespin) → the free-spin count hasn't moved yet.
            $justResolved = in_array($state['State'], ['Spin', 'LastRespin'], true);
            $state['FreeSpinNumber'] = $justResolved
                ? (int) $prevState['FreeSpinNumber'] + 1
                : (int) $prevState['FreeSpinNumber'];
            if (isset($freeSpins['add_free_spins'])) {
                $state['FreeSpins'] += $freeSpins['add_free_spins'];
            }
            $state['TotalWin'] = $state['Win'] + (float) $prevState['TotalWin'];
            $state['FreeState'] = $state['FreeSpinNumber'] <= $state['FreeSpins'] ? 'FreeSpin' : 'LastFreeSpin';
        } elseif (isset($freeSpins['free_spins'])) {
            $state['FreeState'] = 'FirstFreeSpin';
            $state['FreeSpins'] = $freeSpins['free_spins'];
            $state['FreeSpinNumber'] = 1;
            $state['FSPay'] = $freeSpins['pay'];
            $state['Scatter'] = $freeSpins['scatter'];
            $state['ScatterPositions'] = $freeSpins['positions'];
            $state['TotalWin'] = $state['TotalWin'] + $freeSpins['pay'];
        }

        if ($multipliers) {
            $state['Multipliers'] = $multipliers;
            $total = array_sum(array_column($multipliers, 'multiplier'));
            if ($total > 0 && $state['State'] === 'LastRespin') {
                $state['MultiplierApplied'] = $total;
                $extra = $state['TotalWin'] * $total - $state['TotalWin'];
                $state['TotalWin'] += $extra;
                $state['MultiplierExtra'] = $extra;
            }
        }

        return $state;
    }
}
