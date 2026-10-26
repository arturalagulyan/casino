<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\SpinDecider;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * WildCat's SweetCandyMegaDrops on the shared Amatic socket.
 *
 *   a step     pays every symbol held 8 / 10 / 12+ times anywhere (wilds 13
 *              joining; units of total bet / 10), × the multiplier at the
 *              bottom of the 6th reel. A win clears those cells (and the
 *              wilds), the multipliers move down a row (a new one may enter
 *              on top) and new symbols drop in from the strips. Once nothing
 *              wins, rockets fire (their path cleared), then candy bombs (3×3).
 *   bonus      3/4/5 bonus symbols on the settled board → the pick
 *              (`A/u2517,<i>`): 8/12/16 FREE DROPS (the base rules; 2+ bonus
 *              symbols +3 spins) or 2/3/4 MEGA DROPS — values × bet drop onto
 *              the reels, the first full reel pays its sum. Settled on the pick.
 *
 * States: base 9 · 30 first step that wins or fires · 31 next · 33 settled ·
 * 16 the pick; bonus 5 · free drops 34/35/36 (6 quiet spin, 10 more spins,
 * 14/74 last) · mega drops 6 (14 last). Positions are row × 6 + reel.
 */
class AmaticSweetCandyHandler
{
    private const int MAX_TRIES = 300;

    private const int REELS = 5;

    private const int ROWS = 7;

    private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** rocket directions in the client's order: left, up-left, up, up-right, right, down-right, down, down-left */
    private const array DIRS = [[-1, 0], [-1, -1], [0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1]];

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
            'A/u251' => [$this->spin($ctx, (int) ($parts[2] ?? 0), -1, isset($parts[4]) ? (int) $parts[4] : -1)],
            'A/u2566' => [$this->spin($ctx, (int) ($state['bet_index'] ?? 0), (int) end($parts))],
            'A/u2517' => [$this->choose($ctx, (int) ($parts[1] ?? 0))],
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
        // a pick left open by a closed session: settled as FREE DROPS, so the trigger is never lost
        if (($this->state($ctx)['pending'] ?? null) !== null) {
            $this->save($ctx, ['queue' => []] + $this->state($ctx));
            $this->choose($ctx, 0);
        }
        $this->save($ctx, ['queue' => [], 'last' => null, 'pending' => null] + $this->state($ctx));

        return $this->initFrame->withBalance($init, $this->cents($ctx->balance()), 1);
    }

    /**
     * A base spin — or, with `$buy` ≥ 0, a bought pick (price `buy[$buy]` × stake).
     * `$extra`: the extra bet picked — 0 COIN BOOSTER (bonus twice as likely), 1 BONUS HUNT (a 1 in 4 chance of the bonus).
     */
    private function spin(GameContext $ctx, int $betIndex, int $buy = -1, int $extra = -1): string
    {
        $state = $this->state($ctx);
        if (($state['queue'] ?? []) !== []) {
            return $this->next($ctx);
        }
        if (($state['pending'] ?? null) !== null) {
            return (string) $state['last'];                    // the pick is still open
        }
        $t = $this->table($ctx);
        $cfg = (array) $t['lines'];
        $cmd = $buy >= 0 ? 'A/u2566' : 'A/u251';
        $stake = $this->stake($ctx, $betIndex);
        $prices = array_values((array) ($t['buy'] ?? []));
        $booster = $extra === 0 && $buy < 0 && (float) ($cfg['booster_price'] ?? 0) > 0;
        $hunt = $extra === 1 && $buy < 0 && (float) ($cfg['hunt_price'] ?? 0) > 0;
        $price = $buy >= 0 ? (float) ($prices[$buy] ?? 0) : ($booster ? (float) $cfg['booster_price'] : ($hunt ? (float) $cfg['hunt_price'] : 1.0));
        if ($stake === null || $price <= 0) {
            return $this->fmt->error($cmd, 'invalid bet');
        }
        $cost = round($stake * $price, 2);
        if ($ctx->balance() < $cost) {
            return $this->fmt->error($cmd, 'invalid balance');
        }
        $ctx->placeBet($cost);

        $decision = $this->decider->decide($ctx, 'spin', 10, $stake, $booster ? (float) ($cfg['booster_boost'] ?? 2.0) : 1.0);
        $want = $buy >= 0 || ($hunt && random_int(0, 999999) < (float) $cfg['hunt_chance'] * 1000000) ? 'bonus' : $decision->type;
        $ceiling = min($decision->budget, $stake * max(1.0, $decision->maxWinMultiplier));
        if ($buy >= 0 || $hunt) {
            $ceiling = max($ceiling, $cost * 0.2);
        }
        if ($want === 'spin' && (float) ($cfg['win_shape'] ?? 0) > 0) {
            $u = max(1e-9, random_int(1, PHP_INT_MAX - 1) / PHP_INT_MAX);
            $ceiling = min($ceiling, max(0.05, -log($u) * (float) $cfg['win_shape']) * $stake);
        }
        $options = (array) ($cfg['buy_options'] ?? []);
        $force = $buy >= 0 ? (int) (((array) ($options[$buy] ?? []))['scatters'] ?? $cfg['trigger']) : ($want === 'bonus' ? (int) $cfg['trigger'] : 0);

        $best = null;
        $bestScore = INF;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $steps = $this->spinSteps($cfg, (array) $cfg['strips'], $force);
            $win = (float) end($steps)['cumulative'] * $stake;
            $triggered = $this->scatters($cfg, end($steps)) >= (int) $cfg['trigger'];
            // a triggering board's base win must leave room for the bonus within the ceiling
            $ok = match ($want) {
                'bonus' => $triggered && $win <= $ceiling,
                'none' => ! $triggered && $win <= 0,
                default => ! $triggered && $win > 0 && $win <= $ceiling,
            };
            if ($ok) {
                $best = $steps;
                break;
            }
            $score = ($triggered === ($want === 'bonus') ? 0 : 1e9) + max(0, $win - ($want === 'none' ? 0 : $ceiling));
            if ($score < $bestScore) {
                [$best, $bestScore] = [$steps, $score];
            }
        }
        /** @var list<array<string, mixed>> $best */
        $before = $this->cents($ctx->balance());
        $baseWin = (float) end($best)['cumulative'];
        $scatters = $this->scatters($cfg, end($best));
        $triggered = $scatters >= (int) $cfg['trigger'];
        $paid = 0.0;
        if (! $triggered) {
            $total = round($baseWin * $stake, 2);
            $paid = $total > 0 ? $ctx->awardWin($total) : 0.0;
        }
        $frames = $this->baseFrames($best, $betIndex, $stake, $before, $this->cents($ctx->balance()), $triggered);
        $ctx->recordRound(
            new SpinResult(bet: $cost, win: $paid, state: $triggered ? 'bonus' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['grid' => $best[0]['grid'], 'baseWin' => $baseWin * $stake, 'totalWin' => $paid, 'buy' => $buy]]),
        );
        $first = (string) array_shift($frames);
        $pending = $triggered ? ['scatters' => min(5, $scatters), 'stake' => $stake, 'base' => $baseWin, 'ceiling' => $ceiling, 'board' => end($best)] : null;
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'bet_index' => $betIndex, 'pending' => $pending] + $state);

        return $first;
    }

    /** The pick (0 free drops, 1 mega drops): the bonus is played and settled now. */
    private function choose(GameContext $ctx, int $pick): string
    {
        $state = $this->state($ctx);
        $pending = (array) ($state['pending'] ?? []);
        if ($pending === [] || ($state['queue'] ?? []) !== []) {
            return $this->fmt->error('A/u2517', 'no bonus to choose');
        }
        $cfg = (array) $this->table($ctx)['lines'];
        $stake = (float) $pending['stake'];
        $ceiling = (float) $pending['ceiling'];
        $pick = $pick === 1 ? 1 : 0;
        $best = null;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $bonus = $pick === 1 ? $this->megaDrops($cfg, (int) $pending['scatters']) : $this->freeDrops($cfg, (int) $pending['scatters']);
            if ($best === null || $bonus['total'] < $best['total']) {
                $best = $bonus;
            }
            if (($bonus['total'] + (float) $pending['base']) * $stake <= $ceiling) {
                $best = $bonus;
                break;
            }
        }
        /** @var array{spins: list<mixed>, total: float, granted: int} $best */
        $before = $this->cents($ctx->balance());
        $won = round(($best['total'] + (float) $pending['base']) * $stake, 2);
        $paid = $won > 0 ? $ctx->awardWin($won) : 0.0;
        $ctx->recordRound(
            new SpinResult(bet: 0.0, win: $paid, state: 'freespin'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['pick' => $pick, 'spins' => $best['granted'], 'totalWin' => $paid]]),
        );
        $frames = $this->bonusFrames($pick, $best, (array) $pending['board'], $state['bet_index'] ?? 0, $stake, $before, $this->cents($ctx->balance()), $paid, (float) $pending['base']);
        $first = (string) array_shift($frames);
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'pending' => null] + $state);

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
     * One spin: every board shown with what it paid (× total bet) and the
     * rockets / bombs it fires.
     *
     * @param  array<string, mixed>  $cfg
     * @return list<array<string, mixed>>
     */
    public function spinSteps(array $cfg, array $strips, int $forceScatters = 0): array
    {
        $board = $this->fresh($cfg, $strips);
        if ($forceScatters > 0) {
            $board = $this->plantScatters($cfg, $board, $forceScatters);
        }
        $steps = [];
        $cumulative = 0.0;
        $pairs = [];
        for ($guard = 0; $guard < 80; $guard++) {
            [$raw, $clear, $won, $per] = $this->evaluate($cfg, $board['grid']);
            $bottom = (int) $board['mults'][self::ROWS - 1];
            $pay = $raw > 0 ? $raw * max(1, $bottom) : 0.0;
            $cumulative += $pay;
            foreach ($won as $sym => $n) {
                $pairs[] = [$sym, $n];
            }
            $step = $board + ['raw' => $raw, 'pay' => $pay, 'cumulative' => $cumulative, 'pairs' => $pairs, 'per' => $per, 'rockets' => [], 'bombs' => []];
            if ($raw > 0) {
                $steps[] = $step;
                $board = $this->shiftMultipliers($cfg, $board);
                $board = $this->collapse($cfg, $board, $clear, $strips);

                continue;
            }
            $rockets = $this->find($board['grid'], (int) $cfg['rocket']);
            $bombs = $this->find($board['grid'], (int) $cfg['bomb']);
            if ($rockets !== []) {
                $clear = [];
                foreach ($rockets as [$r, $row]) {
                    $dir = (int) $this->pick((array) $cfg['rocket_dirs']);
                    $step['rockets'][] = [$row * 6 + $r, $dir];
                    $clear[$r][$row] = true;
                    [$dr, $dy] = self::DIRS[$dir];
                    for ($x = $r + $dr, $y = $row + $dy; $x >= 0 && $x < self::REELS && $y >= 0 && $y < self::ROWS; $x += $dr, $y += $dy) {
                        if (! in_array($board['grid'][$x][$y], [(int) $cfg['wild'], (int) $cfg['scatter'], (int) $cfg['rocket'], (int) $cfg['bomb']], true)) {
                            $clear[$x][$y] = true;
                        }
                    }
                }
                $steps[] = $step;
                $board = $this->collapse($cfg, $board, $clear, $strips);

                continue;
            }
            if ($bombs !== []) {
                $clear = [];
                foreach ($bombs as [$r, $row]) {
                    $step['bombs'][] = $row * 6 + $r;
                    $clear[$r][$row] = true;
                    for ($x = $r - 1; $x <= $r + 1; $x++) {
                        for ($y = $row - 1; $y <= $row + 1; $y++) {
                            if ($x >= 0 && $x < self::REELS && $y >= 0 && $y < self::ROWS
                                && ! in_array($board['grid'][$x][$y], [(int) $cfg['wild'], (int) $cfg['scatter'], (int) $cfg['bomb']], true)) {
                                $clear[$x][$y] = true;
                            }
                        }
                    }
                }
                $steps[] = $step;
                $board = $this->collapse($cfg, $board, $clear, $strips);

                continue;
            }
            $steps[] = $step;
            break;
        }

        return $steps;
    }

    /**
     * Pay anywhere: each symbol held `tiers` times or more (wilds joining a
     * symbol that is there) pays its tier; the cells it won from (and the wilds) go.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{0: float, 1: array<int, array<int, bool>>, 2: array<int, int>, 3: array<int, float>}
     */
    private function evaluate(array $cfg, array $grid): array
    {
        $wild = (int) $cfg['wild'];
        $count = [];
        $wilds = 0;
        foreach ($grid as $col) {
            foreach ($col as $s) {
                if ($s === $wild) {
                    $wilds++;
                } else {
                    $count[$s] = ($count[$s] ?? 0) + 1;
                }
            }
        }
        $tiers = array_map('intval', (array) $cfg['tiers']);
        $raw = 0.0;
        $won = [];
        $per = [];
        foreach ((array) $cfg['pays'] as $sym => $row) {
            $sym = (int) $sym;
            $row = array_slice(array_values((array) $row), -3);
            if (max($row) <= 0 || ! isset($count[$sym])) {
                continue;
            }
            $n = $count[$sym] + $wilds;
            $tier = $n >= $tiers[2] ? 2 : ($n >= $tiers[1] ? 1 : ($n >= $tiers[0] ? 0 : -1));
            if ($tier >= 0) {
                $pay = (float) $row[$tier] / (int) $cfg['pay_unit'];
                $raw += $pay;
                $won[$sym] = $n;
                $per[$sym] = (float) $row[$tier];
            }
        }
        $clear = [];
        if ($won !== []) {
            foreach ($grid as $r => $col) {
                foreach ($col as $row => $s) {
                    if ($s === $wild || isset($won[$s])) {
                        $clear[$r][$row] = true;
                    }
                }
            }
        }

        return [$raw, $clear, $won, $per];
    }

    /**
     * The cleared cells go, the rest falls; each reel draws its new symbols
     * from the strip above its window.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $board
     * @param  array<int, array<int, bool>>  $clear
     * @return array<string, mixed>
     */
    private function collapse(array $cfg, array $board, array $clear, array $strips): array
    {
        foreach ($board['grid'] as $r => $col) {
            $keep = [];
            foreach ($col as $row => $s) {
                if (empty($clear[$r][$row])) {
                    $keep[] = $s;
                }
            }
            $strip = array_values((array) $strips[$r]);
            $need = self::ROWS - count($keep);
            $new = [];
            for ($k = 0; $k < $need; $k++) {
                $board['stops'][$r] = ($board['stops'][$r] - 1 + count($strip)) % count($strip);
                array_unshift($new, (int) $strip[$board['stops'][$r]]);
            }
            $board['grid'][$r] = array_merge($new, $keep);
        }

        return $board;
    }

    /** @param array<string, mixed> $cfg  the multipliers move down a row; one may enter on top */
    private function shiftMultipliers(array $cfg, array $board): array
    {
        array_pop($board['mults']);
        array_pop($board['fresh']);
        $enter = random_int(0, 999999) < (float) $cfg['multiplier_rate'] * 1000000;
        array_unshift($board['mults'], $enter ? (int) $this->pick((array) $cfg['multiplier_values']) : 0);
        array_unshift($board['fresh'], $enter);

        return $board;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{grid: list<list<int>>, stops: list<int>, mults: list<int>, fresh: list<bool>}
     */
    private function fresh(array $cfg, array $strips): array
    {
        $grid = [];
        $stops = [];
        foreach (array_values($strips) as $r => $strip) {
            $strip = array_values((array) $strip);
            $stops[$r] = random_int(0, count($strip) - 1);
            for ($row = 0; $row < self::ROWS; $row++) {
                $grid[$r][$row] = (int) $strip[($stops[$r] + $row) % count($strip)];
            }
        }
        $mults = [];
        for ($row = 0; $row < self::ROWS; $row++) {
            $mults[] = random_int(0, 999999) < (float) $cfg['multiplier_rate'] * 1000000 ? (int) $this->pick((array) $cfg['multiplier_values']) : 0;
        }

        return ['grid' => $grid, 'stops' => $stops, 'mults' => $mults, 'fresh' => array_fill(0, self::ROWS, false)];
    }

    /** @param array<string, mixed> $cfg */
    private function plantScatters(array $cfg, array $board, int $n): array
    {
        $scatter = (int) $cfg['scatter'];
        $have = $this->countIn($board['grid'], $scatter);
        $reels = array_keys(array_filter($board['grid'], fn ($col) => ! in_array($scatter, $col, true)));
        shuffle($reels);
        foreach (array_slice($reels, 0, max(0, $n - $have)) as $r) {
            $board['grid'][$r][random_int(0, self::ROWS - 1)] = $scatter;
        }

        return $board;
    }

    /** @param array<string, mixed> $cfg */
    private function scatters(array $cfg, array $step): int
    {
        return $this->countIn($step['grid'], (int) $cfg['scatter']);
    }

    /**
     * FREE DROPS: the base rules on the bonus strips; a settled board with
     * `retrigger`+ bonus symbols adds `retrigger_spins`.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{spins: list<array{steps: list<array<string, mixed>>, more: int, total: int, left: int}>, total: float, granted: int}
     */
    public function freeDrops(array $cfg, int $scatters): array
    {
        $granted = (int) (((array) $cfg['free_spins'])[$scatters] ?? 8);
        $left = $total = $granted;
        $cfg = ['multiplier_rate' => $cfg['bonus_multiplier_rate'] ?? $cfg['multiplier_rate']] + $cfg;
        $spins = [];
        $sum = 0.0;
        while ($left > 0 && count($spins) < 200) {
            $steps = $this->spinSteps($cfg, (array) $cfg['bonus_strips']);
            $left--;
            $more = $this->scatters($cfg, end($steps)) >= (int) $cfg['retrigger'] ? (int) $cfg['retrigger_spins'] : 0;
            $left += $more;
            $total += $more;
            $sum += (float) end($steps)['cumulative'];
            $spins[] = ['steps' => $steps, 'more' => $more, 'total' => $total, 'left' => $left];
        }

        return ['spins' => $spins, 'total' => round($sum, 4), 'granted' => $granted];
    }

    /**
     * MEGA DROPS: per round, values drop onto random reels step by step until
     * one reel is full; that reel's sum (× bet) is paid.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{spins: list<array{values: list<list<int>>, bits: list<int>, win: float}>, total: float, granted: int}
     */
    public function megaDrops(array $cfg, int $scatters): array
    {
        $rounds = (int) (((array) $cfg['mega_rounds'])[$scatters] ?? 2);
        $spins = [];
        $sum = 0.0;
        for ($k = 0; $k < $rounds; $k++) {
            $stacks = array_fill(0, self::REELS, []);
            $bits = [];
            $winner = -1;
            while ($winner < 0) {
                $step = [];
                for ($r = 0; $r < self::REELS; $r++) {
                    $step[$r] = random_int(0, 999999) < (float) $cfg['mega_drop_rate'] * 1000000 ? 1 : 0;
                }
                // only one reel may fill up on the same step
                foreach ($step as $r => $drop) {
                    if ($drop && count($stacks[$r]) === self::ROWS - 1) {
                        if ($winner >= 0) {
                            $step[$r] = 0;
                        } else {
                            $winner = $r;
                        }
                    }
                }
                foreach ($step as $r => $drop) {
                    if ($drop) {
                        $stacks[$r][] = (int) $this->pick((array) $cfg['mega_values']);
                    }
                }
                $bits = array_merge($bits, $step);
            }
            $win = (float) array_sum($stacks[$winner]);
            $sum += $win;
            // shown top → bottom, the first drop at the bottom
            $values = array_map(fn ($s) => array_merge(array_fill(0, self::ROWS - count($s), 0), array_reverse($s)), $stacks);
            $spins[] = ['values' => $values, 'bits' => $bits, 'win' => $win];
        }

        return ['spins' => $spins, 'total' => round($sum, 4), 'granted' => $rounds];
    }

    /** @return list<array{0: int, 1: int}> */
    private function find(array $grid, int $symbol): array
    {
        $out = [];
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $s) {
                if ($s === $symbol) {
                    $out[] = [$r, $row];
                }
            }
        }

        return $out;
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
     * @param  list<array<string, mixed>>  $steps
     * @return list<string>
     */
    private function baseFrames(array $steps, int $betIndex, float $stake, int $before, int $balance, bool $triggered): array
    {
        $c = fn (float $x) => $this->cents($x * $stake);
        $frames = [];
        $last = count($steps) - 1;
        foreach ($steps as $k => $s) {
            $settled = $k === $last;
            $code = $settled ? ($triggered ? 16 : ($k === 0 ? 9 : 33)) : ($k === 0 ? 30 : 31);
            $frames[] = $this->frame($code, $settled && ! $triggered ? $balance : $before, $c($s['cumulative']), $betIndex, [0, 0, 0, 0, 0, 0], $s, $stake, 0, 0);
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $bonus
     * @param  array<string, mixed>  $board  the settled triggering board
     * @return list<string>
     */
    private function bonusFrames(int $pick, array $bonus, array $board, int $betIndex, float $stake, int $before, int $balance, float $paid, float $baseWin): array
    {
        $c = fn (float $x) => $this->cents($x * $stake);
        $n = (int) $bonus['granted'];
        $running = $c($baseWin);
        $quiet = ['raw' => 0.0, 'pay' => 0.0, 'cumulative' => 0.0, 'pairs' => [], 'per' => [], 'rockets' => [], 'bombs' => []];
        $frames = [$this->frame(5, $before, $running, $betIndex, [$n, $n, 0, 1, 1, 0], $quiet + $board, $stake, $pick, $n)];
        $bonusTotal = 0;
        $count = count($bonus['spins']);
        foreach ($bonus['spins'] as $k => $spin) {
            $lastSpin = $k === $count - 1;
            if ($pick === 1) {
                $win = $c((float) $spin['win']);
                $bonusTotal += $win;
                $grid = array_fill(0, 6, array_fill(0, self::ROWS, 0));
                $grid[5][self::ROWS - 1] = 18;
                $frames[] = $this->frame($lastSpin ? 14 : 6, $lastSpin ? $balance : $before, $lastSpin ? $this->cents($paid) : $running + $bonusTotal, $betIndex,
                    [$n, $n - $k - 1, $win, 1, 1, $bonusTotal], $quiet + ['grid' => array_slice($grid, 0, 5), 'mults' => array_fill(0, self::ROWS, 0), 'fresh' => array_fill(0, self::ROWS, false),
                        'mega' => ['values' => $spin['values'], 'bits' => $spin['bits'], 'win' => $win, 'collect' => true]], $stake, 1, $n);

                continue;
            }
            $steps = $spin['steps'];
            $stepsLast = count($steps) - 1;
            foreach ($steps as $j => $s) {
                $settled = $j === $stepsLast;
                $final = $lastSpin && $settled && $spin['more'] === 0;
                $code = $settled ? ($spin['more'] > 0 ? 10 : ($lastSpin ? ($j === 0 ? 14 : 74) : ($j === 0 ? 6 : 36))) : ($j === 0 ? 34 : 35);
                $spinWin = $c((float) $s['cumulative']);
                $frames[] = $this->frame($code, $final ? $balance : $before, $final ? $this->cents($paid) : $running + $bonusTotal + $spinWin, $betIndex,
                    [$spin['total'], $spin['left'], $spinWin, 1, 1, $bonusTotal + $spinWin], $s, $stake, 0, $spin['more'] > 0 ? $spin['more'] : $n);
            }
            $bonusTotal += $c((float) end($steps)['cumulative']);
        }

        return $frames;
    }

    /**
     * WildCat's extended header (20 per-symbol win slots), then the tail
     * (`miK`): version 6, a count, two flags, the board (5 reels + the
     * multiplier reel, 15 where a multiplier entered this spin), the step win
     * (cents) before the multiplier, the (symbol, count) wins so far, the
     * step win after it, the spin's win, the bonus-hunt flag, the pick, the
     * spins, rockets (position, direction), bombs, the values per reel (the
     * multiplier values on the 6th; MEGA DROPS values on the 5) and the drop
     * steps (a hex bit mask, 5 bits per step).
     *
     * @param  array{0:int,1:int,2:int,3:int,4:int,5:int}  $counters
     * @param  array<string, mixed>  $s
     */
    private function frame(int $code, int $balance, int $win, int $betIndex, array $counters, array $s, float $stake, int $pick, int $spins): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $letters = fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => self::LETTERS[(int) $v], $l));
        $nums = fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => $h((int) $v), $l));
        $mega = $s['mega'] ?? null;
        $per = array_map(fn ($sym) => $this->cents((float) ($s['per'][$sym] ?? 0) / 10 * $stake), range(0, 19));
        $grid = $s['grid'];
        $grid[5] = $mega !== null ? array_merge(array_fill(0, self::ROWS - 1, 0), [18]) : array_map(fn ($f) => $f ? 15 : 0, $s['fresh']);
        $values = $mega !== null
            ? array_merge($mega['values'], [array_fill(0, self::ROWS, 0)])
            : array_merge(array_fill(0, self::REELS, array_fill(0, self::ROWS, 0)), [$s['mults']]);
        $raw = $mega !== null ? $mega['win'] : $this->cents((float) $s['raw'] * $stake);
        $pay = $mega !== null ? $mega['win'] : $this->cents((float) $s['pay'] * $stake);
        $spinWin = $mega !== null ? $mega['win'] : $this->cents((float) $s['cumulative'] * $stake);
        $hex = '';
        if ($mega !== null) {
            foreach (array_chunk($mega['bits'], 8) as $byte) {
                $v = 0;
                foreach ($byte as $i => $bit) {
                    $v |= $bit << $i;
                }
                $hex .= sprintf('%02x', $v);
            }
        }
        $out = '-11'.sprintf('%02x', $code).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($balance).$h($win)
            .'06'.str_repeat('10', 6).sprintf('%02x', $betIndex).'0a'
            .implode('', array_map($h, $counters))
            .'06'.str_repeat('10', 6).sprintf('%02x', 20).implode('', array_map($h, $per)).str_repeat('00', 8)
            .$h(6).$h(0).'ff'
            .$h(6).implode('', array_map($nums, $grid))
            .$h($raw).$letters(array_merge(...array_map(fn ($p) => [$p[0], min(63, $p[1])], $s['pairs'])))
            .$h($pay).$h($spinWin).'0'.$h($pick).$h($spins)
            .$letters(array_merge(...array_map(fn ($r) => [$r[0], $r[1]], $s['rockets'])))
            .$letters($s['bombs'])
            .$h(6).implode('', array_map($nums, $values))
            .$h(strlen($hex)).$hex;

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
        return (array) (((array) $ctx->stateGet('features', []))['sweetcandy'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['sweetcandy' => $state]]);
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
