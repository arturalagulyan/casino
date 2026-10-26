<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\SpinDecider;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * WildCat's Dinopups on the shared Amatic socket: a 6×6 tumbling ways slot.
 *
 *   a step     pays its ways (pay × ways, units of total bet / 20; wild 4
 *              substitutes) plus every coin on the board (value × bet), times
 *              the sum of the multipliers on the board (used up then). A ways
 *              win clears its cells, the coins and the multipliers; what is
 *              left falls (jumbo blocks as one piece, bonus symbols stay and
 *              fall) and new symbols drop in — until a step wins no ways.
 *   bonus      3–6 bonus symbols on the settled board → 10/15/20/25 spins,
 *              played and settled on the trigger, pulled frame by frame.
 *
 * States: base 9 no ways win · 30 first winning step · 31 next ones · 33 the
 * settled board (5 when it triggers, 66 bought); bonus 6 spin without ways
 * win (14 the last) · 34 first winning step · 35 next · 36 settled (74 the last).
 */
class AmaticDinopupsHandler
{
    private const int MAX_TRIES = 300;

    private const int SIZE = 6;

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

        return match ($parts[0]) {
            'A/u25' => [$this->init($ctx)],
            'A/u251' => [$this->spin($ctx, (int) ($parts[2] ?? 0), -1, isset($parts[4]) && (int) $parts[4] >= 0)],
            'A/u2566' => [$this->spin($ctx, (int) ($state['bet_index'] ?? 0), (int) end($parts))],
            'A/u256', 'A/u2531', 'A/u2535' => [$this->next($ctx)],
            'A/u250', 'A/u254' => [(string) ($state['last'] ?? $this->fmt->error($parts[0], 'no round'))],
            'A/u350' => ['UPDATE#'.$this->cents($ctx->balance())],
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
        $this->save($ctx, ['queue' => [], 'last' => null] + $this->state($ctx));

        return $this->initFrame->withBalance($init, $this->cents($ctx->balance()), 1);
    }

    /** A base spin — or, with `$buy` ≥ 0, a bought bonus (price `buy[$buy]` × stake). */
    private function spin(GameContext $ctx, int $betIndex, int $buy = -1, bool $booster = false): string
    {
        $state = $this->state($ctx);
        if (($state['queue'] ?? []) !== []) {
            return $this->next($ctx);
        }
        $t = $this->table($ctx);
        $cfg = (array) $t['lines'];
        $cmd = $buy >= 0 ? 'A/u2566' : 'A/u251';
        $stake = $this->stake($ctx, $betIndex);
        $prices = array_values((array) ($t['buy'] ?? []));
        $booster = $booster && $buy < 0 && (float) ($cfg['booster_price'] ?? 0) > 0;
        $price = $buy >= 0 ? (float) ($prices[$buy] ?? 0) : ($booster ? (float) $cfg['booster_price'] : 1.0);
        if ($stake === null || $price <= 0) {
            return $this->fmt->error($cmd, 'invalid bet');
        }
        $cost = round($stake * $price, 2);
        if ($ctx->balance() < $cost) {
            return $this->fmt->error($cmd, 'invalid balance');
        }
        $ctx->placeBet($cost);

        $decision = $this->decider->decide($ctx, 'spin', 20, $stake, $booster ? (float) ($cfg['booster_boost'] ?? 2.0) : 1.0);
        $want = $buy >= 0 ? 'bonus' : $decision->type;
        $ceiling = min($decision->budget, $stake * max(1.0, $decision->maxWinMultiplier));
        if ($buy >= 0) {
            $ceiling = max($ceiling, $cost * 0.2);
        }
        // a base win is taken up to a target drawn around `win_shape` × bet: the boards alone would pay
        // bigger wins far more often than the live server does
        if ($want === 'spin' && (float) ($cfg['win_shape'] ?? 0) > 0) {
            $u = max(1e-9, random_int(1, PHP_INT_MAX - 1) / PHP_INT_MAX);
            $ceiling = min($ceiling, max(0.05, -log($u) * (float) $cfg['win_shape']) * $stake);
        }
        $options = (array) ($cfg['buy_options'] ?? []);
        $force = $buy >= 0 ? (int) (((array) ($options[$buy] ?? []))['scatters'] ?? $cfg['trigger']) : ($want === 'bonus' ? (int) $cfg['trigger'] : 0);

        $best = null;
        $bestScore = INF;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $round = $this->round($cfg, $force);
            $win = $round['total'] * $stake;
            $triggered = $round['bonus'] !== [];
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
        $before = $this->cents($ctx->balance());
        $total = round($best['total'] * $stake, 2);
        $paid = $total > 0 ? $ctx->awardWin($total) : 0.0;
        $frames = $this->frames($best, $betIndex, $stake, $before, $this->cents($ctx->balance()), $paid, $buy >= 0);
        $ctx->recordRound(
            new SpinResult(bet: $cost, win: $paid, state: $best['bonus'] !== [] ? 'bonus' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => [
                'grid' => $best['base'][0]['grid'], 'baseWin' => $best['win'] * $stake, 'bonusSpins' => count($best['bonus']), 'totalWin' => $paid, 'buy' => $buy,
            ]]),
        );
        $first = (string) array_shift($frames);
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'bet_index' => $betIndex] + $state);

        return $first;
    }

    private function next(GameContext $ctx): string
    {
        $state = $this->state($ctx);
        $queue = (array) ($state['queue'] ?? []);
        if ($queue === []) {
            return (string) ($state['last'] ?? $this->fmt->error('A/u256', 'no bonus'));
        }
        $frame = (string) array_shift($queue);
        $this->save($ctx, ['queue' => $queue, 'last' => $frame] + $state);

        return $frame;
    }

    // ---- maths -----------------------------------------------------

    /**
     * A base tumble sequence and, when its settled board holds 3+ bonus
     * symbols, the bonus. Wins in total-bet multiples.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{base: list<array<string, mixed>>, win: float, bonus: list<list<array<string, mixed>>>, total: float, granted: int}
     */
    public function round(array $cfg, int $forceScatters = 0): array
    {
        $board = $this->fresh($cfg, (array) $cfg['strips'], true);
        if ($forceScatters > 0) {
            $board = $this->plantScatters($cfg, $board, $forceScatters);
        }
        $base = $this->tumble($cfg, $board, (array) $cfg['strips']);
        $win = array_sum(array_column($base, 'pay'));
        $scatters = $this->countIn(end($base)['grid'], (int) $cfg['scatter']);
        $granted = 0;
        foreach ((array) $cfg['free_spins'] as $n => $spins) {
            if ($scatters >= (int) $n) {
                $granted = (int) $spins;
            }
        }
        $bonus = [];
        $bonusWin = 0.0;
        for ($k = 0; $k < $granted; $k++) {
            $steps = $this->tumble($cfg, $this->fresh($cfg, (array) $cfg['bonus_strips'], false), (array) $cfg['bonus_strips']);
            $bonusWin += array_sum(array_column($steps, 'pay'));
            $bonus[] = $steps;
        }

        return ['base' => $base, 'win' => $win, 'bonus' => $bonus, 'total' => round($win + $bonusWin, 4), 'granted' => $granted];
    }

    /**
     * One spin's steps: every board shown, with what it paid (× total bet).
     *
     * @param  array<string, mixed>  $cfg
     * @param  array{grid: list<list<int>>, blocks: list<array{0: int, 1: int, 2: int, 3: int}>, coins: list<list<int>>, mults: list<list<int>>}  $board
     * @return list<array<string, mixed>>
     */
    private function tumble(array $cfg, array $board, array $strips): array
    {
        $steps = [];
        $cumulative = 0.0;
        for ($guard = 0; $guard < 60; $guard++) {
            [$ways, $cells, $per] = $this->ways($cfg, $board['grid']);
            $coins = (float) array_sum(array_map('array_sum', $board['coins']));
            $mult = (int) array_sum(array_map('array_sum', $board['mults']));
            $raw = $ways / (int) $cfg['ways_unit'] + $coins;
            $pay = $raw * max(1, $mult);
            $cumulative += $pay;
            $steps[] = $board + ['raw' => $raw, 'pay' => $pay, 'cumulative' => $cumulative, 'won' => $ways > 0, 'per' => $per];
            if ($ways <= 0) {
                break;
            }
            // the winning cells, the coins and the multipliers go; the rest falls, new symbols drop in
            foreach ($board['grid'] as $r => $col) {
                foreach ($col as $row => $s) {
                    if ($s === (int) $cfg['coin'] || $s === (int) $cfg['multiplier']) {
                        $cells[$r][$row] = true;
                    }
                }
            }
            $board = $this->refill($cfg, $this->collapse($board, $cells), $strips);
        }

        return $steps;
    }

    /**
     * Ways from the left: per paying symbol the reels in a row holding it
     * (or a wild), pay × the product of its cells per reel. Also the cells
     * that won.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{0: float, 1: array<int, array<int, bool>>, 2: array<int, float>}
     */
    private function ways(array $cfg, array $grid): array
    {
        $wild = (int) $cfg['wild'];
        $total = 0.0;
        $cells = [];
        $per = [];
        foreach ((array) $cfg['pays'] as $sym => $row) {
            $sym = (int) $sym;
            if (max((array) $row) <= 0) {
                continue;
            }
            $w = 1;
            $n = 0;
            foreach ($grid as $col) {
                $c = count(array_filter($col, fn ($s) => $s === $sym || $s === $wild));
                if ($c === 0) {
                    break;
                }
                $w *= $c;
                $n++;
            }
            $pay = (float) (((array) $row)[$n - 1] ?? 0);
            if ($n >= 3 && $pay > 0) {
                $total += $pay * $w;
                $per[$sym] = $pay * $w;
                for ($r = 0; $r < $n; $r++) {
                    foreach ($grid[$r] as $i => $s) {
                        if ($s === $sym || $s === $wild) {
                            $cells[$r][$i] = true;
                        }
                    }
                }
            }
        }

        return [$total, $cells, $per];
    }

    /**
     * Clears the cells (a jumbo block goes whole when any of its cells goes)
     * and lets everything left fall — a block as one piece, as far as every
     * column under it allows.
     *
     * @param  array<string, mixed>  $board
     * @param  array<int, array<int, bool>>  $clear
     * @return array<string, mixed>
     */
    private function collapse(array $board, array $clear): array
    {
        $blocks = [];
        foreach ($board['blocks'] as [$r0, $row0, $size, $sym]) {
            $hit = false;
            for ($r = $r0; $r < $r0 + $size; $r++) {
                for ($row = $row0; $row < $row0 + $size; $row++) {
                    $hit = $hit || ! empty($clear[$r][$row]);
                }
            }
            if ($hit) {
                for ($r = $r0; $r < $r0 + $size; $r++) {
                    for ($row = $row0; $row < $row0 + $size; $row++) {
                        $clear[$r][$row] = true;
                    }
                }
            } else {
                $blocks[] = [$r0, $row0, $size, $sym];
            }
        }
        // units: every block, and every single cell left (with its coin / multiplier value)
        $units = [];
        $inBlock = [];
        foreach ($blocks as $b => [$r0, $row0, $size, $sym]) {
            $units[] = ['block' => $b, 'r' => $r0, 'row' => $row0, 'size' => $size, 'sym' => $sym];
            for ($r = $r0; $r < $r0 + $size; $r++) {
                for ($row = $row0; $row < $row0 + $size; $row++) {
                    $inBlock["$r,$row"] = true;
                }
            }
        }
        foreach ($board['grid'] as $r => $col) {
            foreach ($col as $row => $s) {
                if (empty($clear[$r][$row]) && ! isset($inBlock["$r,$row"]) && $s !== -1) {
                    $units[] = ['block' => null, 'r' => $r, 'row' => $row, 'size' => 1, 'sym' => $s,
                        'coin' => $board['coins'][$r][$row], 'mult' => $board['mults'][$r][$row]];
                }
            }
        }
        // fall: lowest units first, each as far as the columns under it are free
        usort($units, fn ($a, $b) => ($b['row'] + $b['size']) <=> ($a['row'] + $a['size']));
        $occupied = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));
        foreach ($units as &$u) {
            $row = $u['row'];
            while ($row + $u['size'] < self::SIZE) {
                $free = true;
                for ($r = $u['r']; $r < $u['r'] + $u['size']; $r++) {
                    $free = $free && ! $occupied[$r][$row + $u['size']];
                }
                if (! $free) {
                    break;
                }
                $row++;
            }
            $u['row'] = $row;
            for ($r = $u['r']; $r < $u['r'] + $u['size']; $r++) {
                for ($y = $row; $y < $row + $u['size']; $y++) {
                    $occupied[$r][$y] = true;
                }
            }
        }
        unset($u);
        $empty = $this->emptyBoard();
        $out = ['grid' => array_fill(0, self::SIZE, array_fill(0, self::SIZE, -1)), 'blocks' => []] + $empty;
        foreach ($units as $u) {
            for ($r = $u['r']; $r < $u['r'] + $u['size']; $r++) {
                for ($y = $u['row']; $y < $u['row'] + $u['size']; $y++) {
                    $out['grid'][$r][$y] = $u['sym'];
                }
            }
            if ($u['block'] !== null) {
                $out['blocks'][] = [$u['r'], $u['row'], $u['size'], $u['sym']];
            } else {
                $out['coins'][$u['r']][$u['row']] = $u['coin'];
                $out['mults'][$u['r']][$u['row']] = $u['mult'];
            }
        }

        return $out;
    }

    /**
     * New symbols into the empty (-1) cells.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function refill(array $cfg, array $board, array $strips): array
    {
        foreach ($board['grid'] as $r => $col) {
            foreach ($col as $row => $s) {
                if ($s === -1) {
                    $strip = array_values((array) $strips[$r]);
                    $board = $this->place($cfg, $board, $r, $row, (int) $strip[random_int(0, count($strip) - 1)]);
                }
            }
        }

        return $board;
    }

    /**
     * A fresh board from the strips, with jumbo blocks laid over it (base only).
     *
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private function fresh(array $cfg, array $strips, bool $jumbos): array
    {
        $board = ['grid' => array_fill(0, self::SIZE, array_fill(0, self::SIZE, -1)), 'blocks' => []] + $this->emptyBoard();
        foreach (array_values($strips) as $r => $strip) {        // a window of each strip (its stacks show)
            $strip = array_values((array) $strip);
            $at = random_int(0, count($strip) - 1);
            for ($row = 0; $row < self::SIZE; $row++) {
                $board = $this->place($cfg, $board, $r, $row, (int) $strip[($at + $row) % count($strip)]);
            }
        }
        if (! $jumbos) {
            return $board;
        }
        $taken = [];
        foreach ((array) ($cfg['jumbo_rates'] ?? []) as $size => $rate) {
            $size = (int) $size;
            // a Poisson count of blocks of this size
            $n = 0;
            for ($p = exp(-(float) $rate), $x = random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX; $x > $p && $n < 4; $n++) {
                $x *= random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
            }
            for ($k = 0; $k < $n; $k++) {
                [$r0, $row0] = [random_int(0, self::SIZE - $size), random_int(0, self::SIZE - $size)];
                $cells = [];
                for ($r = $r0; $r < $r0 + $size; $r++) {
                    for ($row = $row0; $row < $row0 + $size; $row++) {
                        $cells[] = "$r,$row";
                    }
                }
                if (array_intersect($cells, $taken) !== []) {
                    continue;
                }
                $taken = array_merge($taken, $cells);
                $sym = (int) $this->pick((array) $cfg['jumbo_symbols']);
                for ($r = $r0; $r < $r0 + $size; $r++) {
                    for ($row = $row0; $row < $row0 + $size; $row++) {
                        $board['grid'][$r][$row] = $sym;
                        $board['coins'][$r][$row] = 0;
                        $board['mults'][$r][$row] = 0;
                    }
                }
                $board['blocks'][] = [$r0, $row0, $size, $sym];
            }
        }

        return $board;
    }

    /** @param array<string, mixed> $cfg */
    private function place(array $cfg, array $board, int $r, int $row, int $sym): array
    {
        $board['grid'][$r][$row] = $sym;
        $board['coins'][$r][$row] = $sym === (int) $cfg['coin'] ? (int) $this->pick((array) $cfg['coin_values']) : 0;
        $board['mults'][$r][$row] = $sym === (int) $cfg['multiplier'] ? (int) $this->pick((array) $cfg['multiplier_values']) : 0;

        return $board;
    }

    /** @param array<string, mixed> $cfg */
    private function plantScatters(array $cfg, array $board, int $n): array
    {
        $scatter = (int) $cfg['scatter'];
        $have = $this->countIn($board['grid'], $scatter);
        $inBlock = [];
        foreach ($board['blocks'] as [$r0, $row0, $size]) {
            for ($r = $r0; $r < $r0 + $size; $r++) {
                for ($row = $row0; $row < $row0 + $size; $row++) {
                    $inBlock["$r,$row"] = true;
                }
            }
        }
        $reels = array_keys(array_filter($board['grid'], fn ($col) => ! in_array($scatter, $col, true)));
        shuffle($reels);
        foreach ($reels as $r) {
            if ($have >= $n) {
                break;
            }
            $rows = array_values(array_filter(range(0, self::SIZE - 1), fn ($row) => ! isset($inBlock["$r,$row"])));
            if ($rows !== []) {
                $board = $this->place($cfg, $board, $r, $rows[array_rand($rows)], $scatter);
                $have++;
            }
        }

        return $board;
    }

    /** @return array{coins: list<list<int>>, mults: list<list<int>>} */
    private function emptyBoard(): array
    {
        return ['coins' => array_fill(0, self::SIZE, array_fill(0, self::SIZE, 0)), 'mults' => array_fill(0, self::SIZE, array_fill(0, self::SIZE, 0))];
    }

    private function countIn(array $grid, int $symbol): int
    {
        $n = 0;
        foreach ($grid as $col) {
            $n += count(array_keys($col, $symbol, true));
        }

        return $n;
    }

    /** @param array<int|string, int|float> $weights value => weight */
    private function pick(array $weights): int|string
    {
        $x = random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX * array_sum($weights);
        foreach ($weights as $value => $w) {
            if (($x -= $w) < 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    // ---- frames ----------------------------------------------------

    /**
     * @param  array<string, mixed>  $round
     * @return list<string>
     */
    private function frames(array $round, int $betIndex, float $stake, int $before, int $balance, float $paid, bool $bought): array
    {
        $c = fn (float $x) => $this->cents($x * $stake);
        $frames = [];
        $base = $round['base'];
        $last = count($base) - 1;
        $n = (int) $round['granted'];
        $triggered = $round['bonus'] !== [];
        foreach ($base as $k => $s) {
            $settled = $k === $last;
            $code = $settled ? ($triggered ? ($bought ? 66 : 5) : ($k === 0 ? 9 : 33)) : ($k === 0 ? 30 : 31);
            $final = $settled && ! $triggered;
            $frames[] = $this->frame($code, $final ? $balance : $before, $c($s['cumulative']), $betIndex,
                $triggered && $settled ? [$n, $n, 0, 1, 1, 0] : [0, 0, 0, 0, 0, 0], $s, $c($s['raw']), $c($s['pay']), $c($s['cumulative']), $triggered ? $n : 0);
        }
        $running = $c($round['win']);
        $bonusTotal = 0;
        foreach ($round['bonus'] as $k => $steps) {
            $lastSpin = $k === count($round['bonus']) - 1;
            $stepsLast = count($steps) - 1;
            foreach ($steps as $j => $s) {
                $settled = $j === $stepsLast;
                $code = $settled ? ($j === 0 ? ($lastSpin ? 14 : 6) : ($lastSpin ? 74 : 36)) : ($j === 0 ? 34 : 35);
                $spin = $c($s['cumulative']);
                $final = $lastSpin && $settled;
                $frames[] = $this->frame($code, $final ? $balance : $before, $final ? $this->cents($paid) : $running + $spin, $betIndex,
                    [$n, $n - $k - 1, $spin, 1, 1, $bonusTotal + $spin], $s, $c($s['raw']), $c($s['pay']), $spin, $n);
            }
            $spinCents = $c((float) end($steps)['cumulative']);
            $running += $spinCents;
            $bonusTotal += $spinCents;
        }

        return $frames;
    }

    /**
     * WildCat's extended header (per-symbol ways wins), then the tail (`Y8x`): version 3,
     * a count, two flags, the board (letters), the step win (cents, before the
     * multiplier), mystery symbols (none), the step win after it, the spin's
     * win so far, the bonus-hunt flag, spins won, the jumbo blocks (position,
     * size, symbol), the multipliers (position, value), the coin positions and
     * the coin values per cell. Positions are row × 6 + reel.
     *
     * @param  array{0:int,1:int,2:int,3:int,4:int,5:int}  $counters
     * @param  array<string, mixed>  $s
     */
    private function frame(int $code, int $balance, int $win, int $betIndex, array $counters, array $s, int $raw, int $pay, int $spinWin, int $spinsWon): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $letters = fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => self::LETTERS[(int) $v], $l));
        $jumbo = [];
        foreach ($s['blocks'] as [$r, $row, $size, $sym]) {
            array_push($jumbo, $row * self::SIZE + $r, $size, $sym);
        }
        $mults = [];
        $coins = [];
        foreach ($s['grid'] as $r => $col) {
            foreach ($col as $row => $sym) {
                if ($s['mults'][$r][$row] > 0) {
                    array_push($mults, $row * self::SIZE + $r, $s['mults'][$r][$row]);
                }
                if ($s['coins'][$r][$row] > 0) {
                    $coins[] = $row * self::SIZE + $r;
                }
            }
        }
        $out = '-11'.sprintf('%02x', $code).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($balance).$h($win)
            .'06'.str_repeat('10', 6).sprintf('%02x', $betIndex).'0a'
            .implode('', array_map($h, $counters))
            .'06'.str_repeat('10', 6)
            .sprintf('%02x', 16).implode('', array_map(fn ($sym) => $h(round((float) ($s['per'][$sym] ?? 0))), range(0, 15))).str_repeat('00', 8)
            .$h(3).$h(0).'ff'
            .$h(self::SIZE).implode('', array_map($letters, $s['grid']))
            .$h($raw).$letters([]).$h($pay).$h($spinWin).'0'.$h($spinsWon)
            .$letters($jumbo).$letters($mults).$letters($coins)
            .$h(self::SIZE).implode('', array_map(fn ($col) => $h(count($col)).implode('', array_map($h, $col)), $s['coins']));

        return $out.'#101010';
    }

    // ---- config / state ------------------------------------------------

    /** @return array<string, mixed> */
    private function table(GameContext $ctx): array
    {
        return (array) ($ctx->config()->bonusConfig()['table'] ?? []);
    }

    /** @return array<string, mixed> */
    private function state(GameContext $ctx): array
    {
        return (array) (((array) $ctx->stateGet('features', []))['dinopups'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['dinopups' => $state]]);
    }

    private function stake(GameContext $ctx, int $index): ?float
    {
        $t = $this->table($ctx);
        $init = $this->initFrame->tail((string) ($t['init'] ?? ''), 1);
        $step = $init['ladder'][$index] ?? null;

        return $step === null ? null : round($step * (int) ($t['bet_factor'] ?? $init['coins']) / 100, 2);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
