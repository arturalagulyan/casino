<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\SpinDecider;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * WildCat Gaming "Hold & Win" titles (JadeEmperor, DoubleJokerHoldAndWin,
 * ElToroFlamenco) on the shared Amatic socket. Wire format from each
 * client's own parser (base `A/u251` frame + the game's tail) and Amatic's
 * live server:
 *
 *   base game  3×3 on the real strips (from Amatic's init), fixed lines,
 *              wild; COIN symbols on the coin reels carry per-spin values
 *              (`values[reel][coin − first coin]` × total bet); COLLECTORs
 *              land on the collector reel only. Some games pay a single coin
 *              that lands with a collector at once (× 2 per multiplier collector).
 *   trigger    a collector + 2 coins (or the "Lucky" feature adding them)
 *   bonus      the bonus board's cells spin on their own; collectors stick and
 *              bank every coin landing from then on (coins don't stick — their
 *              cells spin again); a multiplier collector adds multipliers to
 *              random coin cells; anything landing resets the respins to 3;
 *              the collectors' totals are paid at the end
 *
 *   A/u25 init · A/u251,<lines>,<bet>,… spin · A/u2546,… next respin
 *   A/u2566,…,<n> buy · A/u250 / A/u254 resync · A/u350 balance
 *
 * States: 9 base spin · 45 bonus won (64 bought) · 46 respin · 48 bonus over.
 * The whole round is played and settled on the spin; respins are pulled
 * frame by frame. Outcome type is gated by {@see SpinDecider} like every slot.
 *
 * Tail layouts: `v2` (JadeEmperor: 2-hex lists, the 3×3 board in the bonus)
 * and `v5` (DoubleJoker / ElToro: number lists, a 5×4 bonus board of coin
 * columns around the collector column, per-cell multipliers).
 */
class AmaticHoldWinHandler
{
    private const int MAX_TRIES = 400;

    private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    public function __construct(
        private readonly AmaticFormatter $fmt,
        private readonly AmaticWildcatInit $initFrame,
        private readonly SpinDecider $decider,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    public function dispatch(GameContext $ctx, array $request): array
    {
        $parts = explode(',', (string) strtok((string) ($request['gameData'] ?? ''), ';'));
        $state = $this->state($ctx);

        return match (true) {
            $parts[0] === 'A/u25' => [$this->init($ctx)],
            $parts[0] === 'A/u251' => [$this->spin($ctx, (int) ($parts[2] ?? 0))],
            $parts[0] === 'A/u2566' => [$this->spin($ctx, (int) ($state['bet_index'] ?? 0), (int) end($parts))],
            in_array($parts[0], ['A/u2546', 'A/u2547', 'A/u2548', 'A/u2531'], true) => [$this->next($ctx)],
            in_array($parts[0], ['A/u250', 'A/u254'], true) => [$state['last'] ?? $this->fmt->error($parts[0], 'no round')],
            $parts[0] === 'A/u350' => ['UPDATE#'.$this->cents($ctx->balance())],
            default => [$this->fmt->error($parts[0], 'unknown command')],
        };
    }

    // ---- commands --------------------------------------------------

    private function init(GameContext $ctx): string
    {
        $init = (string) ($this->table($ctx)['init'] ?? '');
        if ($init === '') {
            return $this->fmt->error('A/u25', 'game not configured');
        }
        // respins left over from a closed session were paid with their spin
        $this->save($ctx, ['queue' => [], 'last' => null] + $this->state($ctx));

        return $this->initFrame->withBalance($init, $this->cents($ctx->balance()), $this->symbolWidth($ctx));
    }

    /** A base spin — or, with `$buy` ≥ 0, the bought bonus (price `buy[$buy]` × stake). */
    private function spin(GameContext $ctx, int $betIndex, int $buy = -1): string
    {
        $state = $this->state($ctx);
        if (($state['queue'] ?? []) !== []) {
            return $this->next($ctx);
        }
        $t = $this->table($ctx);
        $hw = (array) $t['holdwin'];
        $stake = $this->stake($ctx, $betIndex);
        $prices = array_values((array) ($t['buy'] ?? []));
        $price = $buy >= 0 ? (float) ($prices[$buy] ?? 0) : 1.0;
        if ($stake === null || $price <= 0) {
            return $this->fmt->error($buy >= 0 ? 'A/u2566' : 'A/u251', 'invalid bet');
        }
        $cost = round($stake * $price, 2);
        if ($ctx->balance() < $cost) {
            return $this->fmt->error($buy >= 0 ? 'A/u2566' : 'A/u251', 'invalid balance');
        }
        $ctx->placeBet($cost);

        $decision = $this->decider->decide($ctx, 'spin', count((array) $hw['lines']), $stake);
        $want = $buy >= 0 ? 'bonus' : $decision->type;
        $ceiling = min($decision->budget, $stake * max(1.0, $decision->maxWinMultiplier));
        if ($buy >= 0) {
            $ceiling = max($ceiling, $cost * 0.2);
        }
        $boost = $buy >= 0 ? (array) (((array) ($hw['buy_options'] ?? []))[$buy] ?? []) : [];

        $best = null;
        $bestScore = INF;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $round = $this->round($hw, $want === 'bonus', $boost);
            $win = ($round['line_win'] + $round['collect_win'] + $round['bonus_win']) * $stake;
            $triggered = $round['bonus'] !== null;
            $ok = match ($want) {
                'bonus' => $triggered && $win <= $ceiling,
                'none' => ! $triggered && $win <= 0,
                default => ! $triggered && $win > 0 && $win <= $ceiling,
            };
            if ($ok) {
                $best = $round;
                break;
            }
            $score = ($triggered === ($want === 'bonus') ? 0 : 1e9) + max(0, $win - ($want === 'none' ? 0 : $ceiling));
            if ($score < $bestScore) {
                [$best, $bestScore] = [$round, $score];
            }
        }
        /** @var array<string, mixed> $best */
        $baseWin = round(($best['line_win'] + $best['collect_win']) * $stake, 2);
        $bonusWin = $best['bonus'] !== null ? round($best['bonus_win'] * $stake, 2) : 0.0;
        $total = $baseWin + $bonusWin;
        $paid = $total > 0 ? $ctx->awardWin($total) : 0.0;
        if ($paid + 0.001 < $total) {   // the pool clamped it — the base win first
            $baseWin = min($baseWin, $paid);
            $bonusWin = max(0.0, $paid - $baseWin);
        }

        $frames = $this->frames($ctx, $best, $state, $betIndex, $stake, $baseWin, $bonusWin, $buy >= 0);
        $ctx->recordRound(
            new SpinResult(bet: $cost, win: $paid, state: $buy >= 0 ? 'bonus' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => [
                'grid' => $best['grid'], 'baseWin' => $baseWin, 'bonusWin' => $bonusWin,
                'respins' => $best['bonus'] !== null ? count($best['bonus']['steps']) : 0, 'buy' => $buy,
            ]]),
        );
        $first = (string) array_shift($frames);
        $counter = $best['bonus'] !== null ? 0 : (int) ($state['coin_counter'] ?? 0) + ($best['coins'] !== [] ? 1 : 0);
        // spins with a coin, per reel (v8 shows them); both counters restart with a bonus
        $reelCounter = array_map('intval', array_pad((array) ($state['reel_counter'] ?? []), count($best['grid']), 0));
        foreach (array_keys($best['coins']) as $r) {
            $reelCounter[$r]++;
        }
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'bet_index' => $betIndex, 'coin_counter' => $counter,
            'reel_counter' => $best['bonus'] !== null ? [] : $reelCounter]);

        return $first;
    }

    private function next(GameContext $ctx): string
    {
        $state = $this->state($ctx);
        $queue = (array) ($state['queue'] ?? []);
        if ($queue === []) {
            return (string) ($state['last'] ?? $this->fmt->error('A/u2546', 'no bonus'));
        }
        $frame = (string) array_shift($queue);
        $this->save($ctx, ['queue' => $queue, 'last' => $frame] + $state);

        return $frame;
    }

    // ---- maths -----------------------------------------------------

    /**
     * One base spin (+ its whole bonus when it triggers). Amounts are in
     * total-bet multiples, line pays in line-bet multiples.
     *
     * @param  array<string, mixed>  $hw
     * @param  array<string, mixed>  $boost  a bought option: `pot` (a cash pot lands), `multiplier` (start with a multiplier collector)
     * @return array{grid: list<list<int>>, values: list<list<int>>, line_win: float, line_pays: list<float>, collect_win: float, coins: array<int, array<int, int>>, collectors: array<int, int>, lucky: list<list<int>>, bonus: ?array<string, mixed>, bonus_win: float}
     */
    public function round(array $hw, bool $allowLucky = false, array $boost = []): array
    {
        $strips = array_values((array) $hw['strips']);
        $rows = (int) $hw['rows'];
        $collectorSyms = $this->collectorSymbols($hw);
        $firstCoin = (int) $hw['coin_first'];
        $coinSlots = (int) $hw['coin_slots'];

        $grid = [];
        foreach ($strips as $r => $strip) {
            $at = random_int(0, count($strip) - 1);
            for ($row = 0; $row < $rows; $row++) {
                $grid[$r][$row] = (int) $strip[($at + $row) % count($strip)];
            }
        }
        // this spin's coin values, per reel and coin slot (× total bet)
        $values = [];
        foreach (array_keys($strips) as $r) {
            for ($c = 0; $c < $coinSlots; $c++) {
                $values[$r][$c] = (int) $this->pick((array) $hw['base_values']);
            }
        }

        // ElToro: two reels stacked with the same symbol may win one respin of
        // the third (kept clear of coins / collectors so it can't start a bonus)
        $respin = null;
        if ((float) ($hw['respin_chance'] ?? 0) > 0 && ! $allowLucky) {
            $stacked = [];
            foreach ($grid as $r => $col) {
                if (count(array_unique($col)) === 1 && $col[0] < $firstCoin && ! in_array($col[0], $collectorSyms, true)) {
                    $stacked[$col[0]][] = $r;
                }
            }
            foreach ($stacked as $held) {
                if (count($held) !== 2 || ! $this->chance((float) $hw['respin_chance'])) {
                    continue;
                }
                $respin = ['grid' => $grid, 'mask' => sprintf('%02x', array_sum(array_map(fn ($r) => 1 << $r, $held)))];
                $r = array_values(array_diff(array_keys($grid), $held))[0];
                $strip = $strips[$r];
                for ($try = 0; $try < 30; $try++) {
                    $at = random_int(0, count($strip) - 1);
                    $col = [];
                    for ($row = 0; $row < $rows; $row++) {
                        $col[$row] = (int) $strip[($at + $row) % count($strip)];
                    }
                    if (array_filter($col, fn ($s) => $s >= $firstCoin || in_array($s, $collectorSyms, true)) === []) {
                        break;
                    }
                }
                $grid[$r] = $col;
                break;
            }
        }

        $linePays = [];
        foreach ((array) $hw['lines'] as $line) {
            $linePays[] = $this->linePay($hw, $grid, $line);
        }
        $lineWin = array_sum($linePays) / max(1, count((array) $hw['lines']));

        // coins (reel => row => value) and collectors (row => symbol) on the board
        $coins = [];
        $collectors = [];
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $sym) {
                if ($sym >= $firstCoin && $sym < $firstCoin + $coinSlots) {
                    $coins[$r][$row] = $values[$r][$sym - $firstCoin];
                } elseif (in_array($sym, $collectorSyms, true)) {
                    $collectors[$row] = $sym;
                }
            }
        }
        $coinCount = array_sum(array_map('count', $coins));
        $lucky = array_fill(0, count($strips), array_fill(0, $rows, 0));
        $triggered = $collectors !== [] && $coinCount >= 2;
        // "Lucky": a landed coin may bring the missing collector / coins
        if (! $triggered && $allowLucky && ($coinCount >= 1 || $boost !== [])) {
            $cr = (int) $hw['collector_reel'];
            if ($collectors === []) {
                $row = random_int(0, $rows - 1);
                $collectors[$row] = $lucky[$cr][$row] = ($boost['multiplier'] ?? false) && isset($hw['multi_collector'])
                    ? (int) $hw['multi_collector'] : (int) $hw['collector'];
            }
            $free = [];
            foreach ((array) $hw['coin_reels'] as $r) {
                for ($row = 0; $row < $rows; $row++) {
                    if (! isset($coins[$r][$row])) {
                        $free[] = [$r, $row];
                    }
                }
            }
            shuffle($free);
            while ($coinCount < 2 && $free !== []) {
                [$r, $row] = array_pop($free);
                $coins[$r][$row] = $lucky[$r][$row] = (int) $this->pick((array) $hw['base_values']);
                $coinCount++;
            }
            $triggered = true;
        }

        // a bought "starts with N collectors": add the missing ones on free rows
        if ($triggered && count($collectors) < (int) ($boost['start_collectors'] ?? 0)) {
            $open = array_values(array_diff(range(0, $rows - 1), array_keys($collectors)));
            shuffle($open);
            while (count($collectors) < (int) $boost['start_collectors'] && $open !== []) {
                $row = array_pop($open);
                $collectors[$row] = $lucky[(int) $hw['collector_reel']][$row] = (int) $hw['collector'];
            }
        }
        // a bought "starts with a multiplier collector": make one of them the multiplier kind
        if ($triggered && ($boost['multiplier'] ?? false) && isset($hw['multi_collector']) && ! in_array((int) $hw['multi_collector'], $collectors, true)) {
            $row = (int) array_key_first($collectors);
            $collectors[$row] = $lucky[(int) $hw['collector_reel']][$row] = (int) $hw['multi_collector'];
        }

        // a lone coin with a collector is paid at once (× 2 per multiplier collector)
        $collectWin = 0.0;
        if (! $triggered && ($hw['single_collect'] ?? false) && $collectors !== [] && $coinCount === 1) {
            $coin = (float) array_sum(array_map('array_sum', $coins));
            foreach ($collectors as $sym) {
                $collectWin += $coin * ($sym === ($hw['multi_collector'] ?? null) ? 2 : 1);
            }
        }

        $bonus = $triggered ? $this->bonus($hw, $coins, $collectors, $boost) : null;

        return [
            'grid' => $grid, 'values' => $values, 'line_win' => $lineWin, 'line_pays' => $linePays, 'collect_win' => $collectWin,
            'coins' => $coins, 'collectors' => $collectors, 'lucky' => $lucky, 'bonus' => $bonus, 'bonus_win' => $bonus['win'] ?? 0.0,
            'respin' => $respin,
        ];
    }

    /**
     * The respins on the bonus board (`bonus_coin_cols` × `bonus_rows` coin
     * cells, one collector cell per base row).
     *
     * @param  array<string, mixed>  $hw
     * @param  array<int, array<int, int>>  $coins  base board: reel => row => value
     * @param  array<int, int>  $collectors  row => collector symbol
     * @param  array<string, mixed>  $boost
     * @return array{start: array<string, mixed>, steps: list<array<string, mixed>>, win: float}
     */
    private function bonus(array $hw, array $coins, array $collectors, array $boost = []): array
    {
        $rows = (int) $hw['rows'];
        $cells = [];
        foreach ($this->bonusCoinCols($hw) as $c) {
            for ($row = 0; $row < $this->bonusRows($hw); $row++) {
                $cells[] = [$c, $row];
            }
        }
        $mults = [];                                   // "col.row" => multiplier added so far
        $multiCount = 0;
        $addMultipliers = function () use (&$mults, &$multiCount, $hw, $cells): void {
            $steps = (array) ($hw['multiplier_steps'] ?? [2]);
            $add = (int) ($steps[min($multiCount, count($steps) - 1)] ?? 2);
            $multiCount++;
            $picked = array_rand($cells, min(count($cells), (int) ($hw['multiplier_cells'] ?? 4)));
            foreach ((array) $picked as $i) {
                $key = $cells[$i][0].'.'.$cells[$i][1];
                $mults[$key] = min((int) ($hw['multiplier_max'] ?? 6), ($mults[$key] ?? 0) + $add);
            }
        };
        foreach ($collectors as $sym) {
            if ($sym === ($hw['multi_collector'] ?? null)) {
                $addMultipliers();
            }
        }

        $totals = array_fill(0, $rows, 0.0);
        $boardValue = 0.0;
        foreach ($coins as $col) {
            foreach ($col as $v) {
                $boardValue += $this->worth($hw, $v);
            }
        }
        foreach (array_keys($collectors) as $row) {
            $totals[$row] = $boardValue;
        }
        $start = ['coins' => $coins, 'collectors' => $collectors, 'totals' => $totals, 'mults' => $mults];

        $steps = [];
        $left = (int) ($hw['respins'] ?? 3);
        $potPending = (bool) ($boost['pot'] ?? false);
        $forceMulti = isset($hw['multi_collector']) ? (int) ($boost['extra_multi'] ?? 0) : 0;
        while ($left > 0 && count($steps) < 200) {
            $landed = [];                              // "col.row" => [shown value, worth]
            // coins don't stick: the collectors bank them and every coin cell spins again
            foreach ($cells as [$c, $row]) {
                if ($this->chance((float) $hw['coin_chance'])) {
                    $landed["{$c}.{$row}"] = (int) $this->pick((array) $hw['bonus_values']);
                }
            }
            if ($potPending && ($pots = array_intersect_key((array) $hw['bonus_values'], (array) $hw['jackpots'])) !== []) {
                [$c, $row] = $cells[array_rand($cells)];
                $landed["{$c}.{$row}"] = (int) $this->pick($pots);
                $potPending = false;
            }
            $newCollectors = [];
            // a bought "more multiplier collectors guaranteed": land them early on
            $forceRow = null;
            if ($forceMulti > 0 && ($open = array_values(array_diff(range(0, $rows - 1), array_keys($collectors)))) !== [] && ($this->chance(0.35) || count($steps) >= 4)) {
                $forceRow = $open[array_rand($open)];
                $forceMulti--;
            }
            for ($row = 0; $row < $rows; $row++) {
                if (! isset($collectors[$row]) && ($row === $forceRow || $this->chance((float) $hw['collector_chance']))) {
                    $multi = isset($hw['multi_collector']) && ($row === $forceRow || $this->chance((float) ($hw['multi_share'] ?? 0.25)));
                    $collectors[$row] = $newCollectors[$row] = $multi ? (int) $hw['multi_collector'] : (int) $hw['collector'];
                    if ($multi) {
                        $addMultipliers();
                    }
                }
            }
            $add = 0.0;
            $shown = [];
            foreach ($landed as $key => $v) {
                $m = max(1, $mults[$key] ?? 1);
                $add += $this->worth($hw, $v) * $m;
                $shown[$key] = isset(((array) $hw['jackpots'])[$v]) ? $v : $v * $m;
            }
            foreach (array_keys($collectors) as $row) {
                $totals[$row] += $add;
            }
            $left = $landed !== [] || $newCollectors !== [] ? (int) ($hw['respins'] ?? 3) : $left - 1;
            $steps[] = ['landed' => $shown, 'collectors' => $newCollectors, 'all_collectors' => $collectors, 'totals' => $totals, 'mults' => $mults, 'left' => $left];
        }

        return ['start' => $start, 'steps' => $steps, 'win' => array_sum($totals)];
    }

    /** @param array<string, mixed> $hw  3 of a kind on a line (wild substitutes), in line-bet multiples */
    private function linePay(array $hw, array $grid, array $line): float
    {
        $wild = (int) $hw['wild'];
        $syms = [];
        foreach ($line as $r => $row) {
            $syms[] = (int) $grid[$r][$row];
        }
        $base = null;
        foreach ($syms as $s) {
            if ($s !== $wild) {
                $base = $s;
                break;
            }
        }
        $base ??= $wild;
        foreach ($syms as $s) {
            if ($s !== $base && $s !== $wild) {
                return 0.0;
            }
        }

        return (float) (((array) $hw['pays'])[$base] ?? 0);
    }

    /** A coin's worth in total-bet multiples (cash-pot codes → their multiple). */
    private function worth(array $hw, int $value): float
    {
        return (float) (((array) $hw['jackpots'])[$value] ?? $value);
    }

    /** @return list<int> */
    private function collectorSymbols(array $hw): array
    {
        return isset($hw['multi_collector']) ? [(int) $hw['collector'], (int) $hw['multi_collector']] : [(int) $hw['collector']];
    }

    /** @return list<int> */
    private function bonusCoinCols(array $hw): array
    {
        return array_values(array_map('intval', (array) ($hw['bonus_coin_cols'] ?? $hw['coin_reels'])));
    }

    private function bonusRows(array $hw): int
    {
        return (int) ($hw['bonus_rows'] ?? $hw['rows']);
    }

    /** @param array<int|string, int|float> $weights value => weight */
    private function pick(array $weights): int|string
    {
        $sum = array_sum($weights);
        $x = random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX * $sum;
        foreach ($weights as $value => $w) {
            if (($x -= $w) < 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    private function chance(float $p): bool
    {
        return random_int(0, 999999) < $p * 1000000;
    }

    // ---- frames ----------------------------------------------------

    /**
     * The spin frame, then (bonus) one frame per respin — the last one
     * (state 48) carrying the bonus win and the settled balance.
     *
     * @param  array<string, mixed>  $round
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function frames(GameContext $ctx, array $round, array $state, int $betIndex, float $stake, float $baseWin, float $bonusWin, bool $bought): array
    {
        $hw = (array) $this->table($ctx)['holdwin'];
        $layout = (string) ($hw['layout'] ?? 'v2');
        $v2 = $layout === 'v2';
        $v8 = $layout === 'v8';
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        // a list as the client reads it: count, then 2-hex items (v2) or numbers (v5 / v8)
        $list = $v2
            ? fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => sprintf('%02x', $v), $l))
            : fn (array $l) => $h(count($l)).implode('', array_map($h, $l));
        $lists = fn (array $ls) => $h(count($ls)).implode('', array_map($list, $ls));
        // v8 sends boards (symbols, lucky additions, multipliers) as letters
        $letterLists = fn (array $ls) => $h(count($ls)).implode('', array_map(
            fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => self::LETTERS[max(0, min(63, (int) $v))], $l)),
            $ls,
        ));
        $boards = $v8 ? $letterLists : $lists;
        $str = fn (string $s) => $h(strlen($s)).$s;
        $grid = $round['grid'];
        $reels = count($grid);
        $rows = (int) $hw['rows'];
        $lines = count((array) $hw['lines']);
        $balance = $this->cents($ctx->balance());
        $bonus = $round['bonus'];
        $preBonus = $balance - $this->cents($bonusWin);
        $baseWinCents = $this->cents($baseWin);
        $collector = (int) $hw['collector'];

        $head = function (int $code, int $bal, int $win, bool $inBonus, float $extraSlot, ?array $pays = null) use ($h, $betIndex, $lines, $round): string {
            $wins = array_map(fn ($p) => $h(round($p)), $pays ?? $round['line_pays']);
            $wins[] = $h(round($extraSlot));

            return '-11'.sprintf('%02x', $code).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($bal).$h($win)
                .'05'.str_repeat('10', 5).sprintf('%02x', $betIndex).sprintf('%02x', $lines)
                .'101010'.($inBonus ? '11' : '10').'1010'
                .'05'.str_repeat('10', 5)
                .sprintf('%02x', count($wins)).implode('', $wins).str_repeat('00', 8);
        };
        // the tail up to the grid: version + flags (+ v8: reel-match masks, round win)
        $tailHead = fn (array $board, string $mask, int $won) => match ($layout) {
            'v8' => $h(8).'f0'.$str($mask).$str($mask).$h($won).$boards($board),
            'v5' => $h(5).'ff0'.$boards($board),
            default => $h(2).'f'.$boards($board),
        };
        $code = $bonus !== null ? ($bought ? 64 : 45) : 9;
        $counter = $bonus !== null ? 0 : (int) ($state['coin_counter'] ?? 0) + ($round['coins'] !== [] ? 1 : 0);
        // v8: spins with a coin, per reel (before / after this one)
        $reelCounter = array_map('intval', array_pad((array) ($state['reel_counter'] ?? []), $reels, 0));
        $reelAfter = $reelCounter;
        foreach (array_keys($round['coins']) as $r) {
            $reelAfter[$r]++;
        }
        $counters = $v8 ? $list($reelCounter).$list($reelAfter) : $h($counter);
        $startTotal = $bonus !== null ? max($bonus['start']['totals'] ?: [0]) : 0;
        // base-board coin values (v5: the collected / triggering ones; v8: every coin)
        $boardValues = array_fill(0, $reels, array_fill(0, $rows, 0));
        if ($v8 || $bonus !== null || $round['collect_win'] > 0) {
            foreach (($bonus['start']['coins'] ?? $round['coins']) as $r => $col) {
                foreach ($col as $row => $v) {
                    $boardValues[$r][$row] = $v;
                }
            }
        }
        $extra = $v2 ? 0 : ($bonus !== null ? $startTotal : $round['collect_win']);

        $frames = [];
        // v8 respin: the first board with the two held reels, then the respun one
        if (($round['respin'] ?? null) !== null) {
            $first = $round['respin'];
            $frames[] = $head(30, $balance - $baseWinCents, 0, false, 0, array_fill(0, $lines, 0))
                .$tailHead($first['grid'], $first['mask'], 0).$counters.$lists($round['values']).$lists($boardValues).'#101010';
            $code = 33;
        }
        $out = $head($code, $bonus !== null ? $preBonus : $balance, $baseWinCents, $bonus !== null, $extra)
            .$tailHead($grid, '', $bonus !== null ? 0 : $baseWinCents)
            .$counters.$lists($round['values'])
            .($v2 ? '' : $lists($boardValues));
        if ($bonus === null) {
            $frames[] = $out.'#101010';

            return $frames;
        }

        $bonusCols = (int) ($hw['bonus_cols'] ?? $reels);
        $bonusRows = $this->bonusRows($hw);
        $collectorCol = (int) ($hw['bonus_collector_col'] ?? $hw['collector_reel']);
        // v8 marks an empty collector slot (and an untouched Lucky cell) with the empty symbol
        $empty = $v8 ? (int) $hw['empty'] : 0;
        $emptyBase = array_fill(0, $reels, array_fill(0, $rows, 0));
        $collectorRow = fn (array $byRow) => array_map(fn ($row) => (int) ($byRow[$row] ?? $empty), range(0, $rows - 1));
        $valueTable = array_values((array) $hw['value_table']);
        $coinId = fn (int $v) => (int) $hw['coin_first'] + max(0, (int) array_search($v, $valueTable, true));
        $emptyBonus = array_fill(0, $bonusCols, array_fill(0, $bonusRows, 0));
        $multGrid = function (array $mults) use ($emptyBonus): array {
            $g = array_map(fn ($col) => array_map(fn () => 1, $col), $emptyBonus);
            foreach ($mults as $key => $m) {
                [$c, $row] = array_map('intval', explode('.', (string) $key));
                $g[$c][$row] = max(1, (int) $m);
            }

            return $g;
        };
        $landedGrid = function (array $landed) use ($emptyBonus): array {
            $g = $emptyBonus;
            foreach ($landed as $key => $v) {
                [$c, $row] = array_map('intval', explode('.', (string) $key));
                $g[$c][$row] = (int) $v;
            }

            return $g;
        };

        // v2: board, count, left, win, value table, values, totals, collectors
        // v5 / v8: count, left, win, values, value table, collectors, totals, multipliers
        $bonusPart = function (array $board, int $count, int $left, int $won, array $values, array $totals, array $collectors, array $mults) use ($h, $list, $lists, $boards, $valueTable, $v2): string {
            $totals = array_map(fn ($v) => (int) round($v), $totals);

            return $v2
                ? $lists($board).$h($count).$h($left).$h($won).$list($valueTable).$lists($values).$list($totals).$list($collectors)
                : $h($count).$h($left).$h($won).$lists($values).$list($valueTable).$list($collectors).$list($totals).$boards($mults);
        };

        $startValues = $v2 ? $boardValues : $emptyBonus;
        $frames[] = $out.$boards($v8 ? array_map(fn ($col) => array_map(fn ($v) => $v ?: $empty, $col), $round['lucky']) : $round['lucky'])
            .$bonusPart($grid, 0, (int) ($hw['respins'] ?? 3), 0, $startValues, $bonus['start']['totals'], $collectorRow($bonus['start']['collectors']), $multGrid($bonus['start']['mults']))
            .'#101010';

        $last = count($bonus['steps']) - 1;
        foreach ($bonus['steps'] as $k => $step) {
            $values = $landedGrid($step['landed']);
            $board = array_fill(0, $reels, array_fill(0, $rows, (int) $hw['empty']));
            if ($v2) {
                foreach ($step['landed'] as $key => $v) {
                    [$c, $row] = array_map('intval', explode('.', (string) $key));
                    $board[$c][$row] = $coinId((int) $v);
                }
                foreach ($step['collectors'] as $row => $sym) {
                    $board[$collectorCol][$row] = $collector;
                }
            }
            $final = $k === $last;
            $frames[] = $head($final ? 48 : 46, $final ? $balance : $preBonus, $final ? $baseWinCents + $this->cents($bonusWin) : $baseWinCents, true, 0, array_fill(0, $lines, 0))
                .$tailHead($grid, '', $v8 ? $this->cents($final ? $bonusWin : array_sum($step['totals']) * $stake) : 0)
                .($v2 ? '' : $lists($v8 ? $emptyBase : $boardValues))
                .$bonusPart($board, $k + 1, $final ? 0 : $step['left'], $final ? $this->cents($bonusWin) : 0, $values, $step['totals'], $collectorRow($step['all_collectors']), $multGrid($step['mults']))
                .'#101010';
        }

        return $frames;
    }

    // ---- config / state ------------------------------------------------

    /** @return array<string, mixed> */
    private function table(GameContext $ctx): array
    {
        return (array) ($ctx->config()->bonusConfig()['table'] ?? []);
    }

    /** Init strips: 2-hex symbols (v2) or numbers (v5, sized by their own length digit). */
    private function symbolWidth(GameContext $ctx): int
    {
        return match (((array) ($this->table($ctx)['holdwin'] ?? []))['layout'] ?? 'v2') {
            'v5' => 0,
            'v8' => 1,
            default => 2,
        };
    }

    /** @return array<string, mixed> */
    private function state(GameContext $ctx): array
    {
        return (array) (((array) $ctx->stateGet('features', []))['holdwin'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['holdwin' => $state]]);
    }

    /** Stake of ladder step `$index` in money: the ladder (cents) × the game's bet factor. */
    private function stake(GameContext $ctx, int $index): ?float
    {
        $t = $this->table($ctx);
        $init = $this->initFrame->tail((string) ($t['init'] ?? ''), $this->symbolWidth($ctx));
        $step = $init['ladder'][$index] ?? null;

        return $step === null ? null : round($step * (int) ($t['bet_factor'] ?? $init['lines']) / 100, 2);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
