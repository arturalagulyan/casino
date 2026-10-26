<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\SpinDecider;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * WildCat's PufferIsland on the shared Amatic socket.
 *
 *   base game  5×3, 20 lines; every wild landing fills its meter (super 13,
 *              extreme 14, insane 15 — kept per bet level)
 *   bonus      a full meter, 3 bonus symbols with an extreme / insane wild
 *              on reel 5, or (super) chance: 1 / 10 / 10 spins on a 5×5
 *              board with 40 lines; the meter's wilds are scattered over
 *              those spins (several on one cell → ×2…×5, the multipliers on a
 *              line multiply, ×5 at most); cash pots pay their value at once.
 *              Played and settled on the trigger, pulled spin by spin (`A/u256`).
 *
 * States: 9 base · 5 bonus won (66 bought) · 6 bonus spin · 14 last.
 * Line pays are line-bet (total / 20) multiples — also on the 40-line board.
 */
class AmaticPufferHandler
{
    private const int MAX_TRIES = 300;

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
            'A/u256' => [$this->next($ctx)],
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
        $state = $this->state($ctx);
        $this->save($ctx, ['queue' => [], 'last' => null] + $state);
        $init = $this->initFrame->withBalance($init, $this->cents($ctx->balance()), 1);
        // the meters of the last bet played, in place of the init's own (the list pair before `|`)
        $meters = $this->meters($state, (int) ($state['bet_index'] ?? 0));
        $min = array_map('intval', (array) (((array) $this->tableCache['lines'])['meter_min'] ?? []));
        $at = strrpos($init, $this->list($min).$this->list($min).'|');

        return $at === false ? $init
            : substr($init, 0, $at).$this->list($meters).$this->list($meters).substr($init, $at + 2 * strlen($this->list($min)));
    }

    /** A base spin — or, with `$buy` ≥ 0, a bought bonus (`buy[$buy]` × stake, `buy_wilds[$buy]` wilds). */
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

        $decision = $this->decider->decide($ctx, 'spin', count((array) $cfg['lines']), $stake, $booster ? (float) ($cfg['booster_boost'] ?? 2.0) : 1.0);
        $want = $buy >= 0 ? 'bonus' : $decision->type;
        $ceiling = min($decision->budget, $stake * max(1.0, $decision->maxWinMultiplier));
        if ($buy >= 0) {
            $ceiling = max($ceiling, $cost * 0.2);
        }
        $variant = $buy >= 0 ? $buy : ($want === 'bonus' ? (int) $this->pick((array) $cfg['bonus_mix']) : -1);
        $meters = $this->meters($state, $betIndex);

        $best = null;
        $bestScore = INF;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $round = $this->round($cfg, $meters, $variant, $buy >= 0);
            $win = $round['total'] * $stake;
            $triggered = $round['variant'] >= 0;
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
        $frames = $this->frames($cfg, $best, $betIndex, $stake, $before, $this->cents($ctx->balance()), $paid, $buy >= 0, (array) ($state['bonus_board'] ?? []));
        $ctx->recordRound(
            new SpinResult(bet: $cost, win: $paid, state: $best['variant'] >= 0 ? 'bonus' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => [
                'grid' => $best['grid'], 'baseWin' => $best['win'] * $stake, 'variant' => $best['variant'], 'wilds' => $best['wilds'], 'totalWin' => $paid, 'buy' => $buy,
            ]]),
        );
        $first = (string) array_shift($frames);
        $all = (array) ($state['meters'] ?? []);
        $all[$betIndex] = $best['meters_after'];
        if ($best['spins'] !== []) {
            $state['bonus_board'] = end($best['spins'])['grid'];
        }
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'bet_index' => $betIndex, 'meters' => $all] + $state);

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
     * A base spin and the bonus it starts. `$variant` ≥ 0 asks for that bonus
     * (0 super / 1 extreme / 2 insane); `$bought` plays it with a full meter.
     * Wins in total-bet multiples.
     *
     * @param  array<string, mixed>  $cfg
     * @param  list<int>  $meters
     * @return array{grid: list<list<int>>, pays: list<float>, win: float, total: float, variant: int, wilds: int, spins: list<array<string, mixed>>, meters_before: list<int>, meters_after: list<int>}
     */
    public function round(array $cfg, array $meters, int $variant = -1, bool $bought = false): array
    {
        $wilds = array_map('intval', (array) $cfg['wilds']);
        $min = array_map('intval', (array) $cfg['meter_min']);
        $max = array_map('intval', (array) $cfg['meter_max']);
        $grid = $this->window((array) $cfg['strips'], (int) $cfg['rows']);
        // an asked-for extreme / insane bonus: 3 bonus symbols, its wild on reel 5
        if (! $bought && $variant >= 1) {
            $grid = $this->plantTrigger($cfg, $grid, $wilds[$variant]);
        }
        // a super-wild (chance) or bought bonus starts on a calm board (see calm())
        if ($bought || $variant === 0) {
            $grid = $this->calm($cfg, $grid);
        }
        $after = $meters;
        foreach ($wilds as $k => $w) {
            $after[$k] = min($max[$k], $meters[$k] + $this->countIn($grid, $w));
        }
        $pays = $this->linePays($cfg, $grid, (array) $cfg['lines'], []);
        $win = array_sum($pays) / (int) $cfg['bet_factor'];
        // what the board itself starts: a full meter, or the bonus symbols with a special wild on reel 5
        $natural = -1;
        $scatters = $this->countIn($grid, (int) $cfg['scatter']);
        $last = $grid[count($grid) - 1];
        foreach ([2, 1] as $k) {
            if ($scatters >= (int) $cfg['trigger'] && in_array($wilds[$k], $last, true)) {
                $natural = $k;
                break;
            }
        }
        foreach ($after as $k => $m) {
            if ($natural < 0 && $m >= $max[$k] && $meters[$k] < $max[$k]) {
                $natural = $k;
            }
        }
        $play = $bought || $variant === 0 ? $variant : $natural;
        $spins = [];
        $bonusWin = 0.0;
        $count = 0;
        $landed = $after;
        if ($play >= 0) {
            $count = $bought ? (int) (((array) ($cfg['buy_wilds'] ?? $max))[$play] ?? $max[$play]) : $after[$play];
            $after[$play] = $min[$play];
            [$spins, $bonusWin] = $this->bonus($cfg, $count, (int) ((array) $cfg['bonus_spins'])[$play]);
        }

        return [
            'grid' => $grid, 'pays' => $pays, 'win' => $win, 'total' => round($win + $bonusWin, 4), 'variant' => $play, 'wilds' => $count,
            'spins' => $spins, 'meters_before' => $meters, 'meters_after' => $after, 'meters_landed' => $landed,
            // a board showing wild / bonus symbols leads in as a plain spin; the bonus starts on the next command
            'lead' => $play >= 0 && $this->calm($cfg, $grid) !== $grid,
        ];
    }

    /**
     * The board a bonus starts on carries no wild or bonus symbol: the
     * client collects those into the meters first and then trips over them
     * entering the bonus (every live trigger board is calm, too).
     *
     * @param  array<string, mixed>  $cfg
     * @param  list<list<int>>  $grid
     * @return list<list<int>>
     */
    private function calm(array $cfg, array $grid): array
    {
        $loud = array_merge(array_map('intval', (array) $cfg['wilds']), [(int) $cfg['scatter']]);
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $sym) {
                if (in_array($sym, $loud, true)) {
                    $grid[$r][$row] = 5 + ($r + $row) % 4;
                }
            }
        }

        return $grid;
    }

    /**
     * The bonus spins: `$wilds` wilds dropped on random spins and cells
     * (stacking to ×5), each spin's 40 lines and cash pots.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private function bonus(array $cfg, int $wilds, int $count): array
    {
        $rows = (int) $cfg['bonus_rows'];
        $cap = (int) $cfg['multiplier_max'];
        $mults = array_fill(0, $count, array_fill(0, 5, array_fill(0, $rows, 0)));
        for ($w = 0; $w < $wilds; $w++) {
            for ($t = 0; $t < 20; $t++) {
                [$s, $r, $row] = [random_int(0, $count - 1), random_int(0, 4), random_int(0, $rows - 1)];
                if ($mults[$s][$r][$row] < $cap) {
                    $mults[$s][$r][$row]++;
                    break;
                }
            }
        }
        $out = [];
        $sum = 0.0;
        $left = $wilds;
        foreach ($mults as $k => $m) {
            $grid = $this->window((array) $cfg['bonus_strips'], $rows);
            // cash pots land on their own, rarely (a strip can't hold them rare enough)
            foreach ($grid as $r => $col) {
                foreach ($col as $row => $sym) {
                    if (random_int(0, 999999) < (float) ($cfg['cashpot_chance'] ?? 0) * 1000000) {
                        $grid[$r][$row] = (int) $this->pick((array) $cfg['cashpot_weights']);
                    }
                }
            }
            $pays = $this->linePays($cfg, $grid, (array) $cfg['bonus_lines'], $m);
            $pots = 0.0;
            foreach ($grid as $r => $col) {
                foreach ($col as $row => $sym) {
                    if ($m[$r][$row] === 0 && isset($cfg['cashpots'][$sym])) {
                        $pots += (float) $cfg['cashpots'][$sym];
                    }
                }
            }
            $spinWin = array_sum($pays) / (int) $cfg['bet_factor'] + $pots;
            $left -= (int) array_sum(array_map('array_sum', $m));
            $sum += $spinWin;
            $out[] = ['grid' => $grid, 'mults' => $m, 'pays' => $pays, 'spin_win' => $spinWin, 'left' => $count - $k - 1, 'wilds_left' => $left];
        }

        return [$out, $sum];
    }

    /**
     * Each line's best left-to-right pay (line-bet multiples); wild cells
     * (`$mults` > 0, or a wild symbol) substitute, their multipliers multiply up to the cap.
     *
     * @param  array<string, mixed>  $cfg
     * @param  list<list<int>>  $lines
     * @return list<float>
     */
    private function linePays(array $cfg, array $grid, array $lines, array $mults): array
    {
        $subs = array_map('intval', (array) $cfg['substitutes']);
        $pays = (array) $cfg['pays'];
        $out = [];
        foreach ($lines as $line) {
            $syms = $ms = [];
            foreach ($line as $r => $row) {
                $m = (int) ($mults[$r][$row] ?? 0);
                $syms[] = $m > 0 ? -1 : (int) $grid[$r][$row];
                $ms[] = $m;
            }
            $isWild = fn (int $s) => $s === -1 || in_array($s, $subs, true);
            $best = 0.0;
            $candidates = array_unique(array_merge(array_filter($syms, fn ($s) => ! $isWild($s)), [(int) $cfg['top']]));
            foreach ($candidates as $sym) {
                $n = 0;
                $mult = 1;
                foreach ($syms as $i => $s) {
                    if ($s !== $sym && ! $isWild($s)) {
                        break;
                    }
                    $n++;
                    $mult *= max(1, $ms[$i]);
                }
                $best = max($best, (float) (((array) ($pays[$sym] ?? []))[$n - 1] ?? 0) * min($mult, (int) $cfg['multiplier_max']));
            }
            $out[] = $best;
        }

        return $out;
    }

    /** 3 bonus symbols on reels 1–4 and `$wild` on reel 5. */
    private function plantTrigger(array $cfg, array $grid, int $wild): array
    {
        $scatter = (int) $cfg['scatter'];
        $rows = (int) $cfg['rows'];
        $last = count($grid) - 1;
        $grid[$last][random_int(0, $rows - 1)] = $wild;
        $have = $this->countIn(array_slice($grid, 0, $last), $scatter);
        $reels = array_keys(array_filter(array_slice($grid, 0, $last), fn ($col) => ! in_array($scatter, $col, true)));
        shuffle($reels);
        foreach (array_slice($reels, 0, max(0, (int) $cfg['trigger'] - $have)) as $r) {
            $grid[$r][random_int(0, $rows - 1)] = $scatter;
        }

        return $grid;
    }

    /** @return list<list<int>> */
    private function window(array $strips, int $rows): array
    {
        $grid = [];
        foreach (array_values($strips) as $r => $strip) {
            $at = random_int(0, count($strip) - 1);
            for ($row = 0; $row < $rows; $row++) {
                $grid[$r][$row] = (int) $strip[($at + $row) % count($strip)];
            }
        }

        return $grid;
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
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $round
     * @return list<string>
     */
    private function frames(array $cfg, array $round, int $betIndex, float $stake, int $before, int $balance, float $paid, bool $bought, array $lastBoard = []): array
    {
        $baseCents = $this->cents($round['win'] * $stake);
        $base = ['grid' => $round['grid'], 'before' => $round['meters_before'], 'after' => $round['meters_after']];
        if ($round['variant'] < 0) {
            return [$this->frame($cfg, 9, $balance, $baseCents, $betIndex, $round['pays'], [0, 0, 0, 0, 0, 0], $base, null)];
        }
        $spins = $round['spins'];
        $n = count($spins);
        $rows = (int) $cfg['bonus_rows'];
        $empty = array_fill(0, 5, array_fill(0, $rows, 0));
        // wilds still to come before / after this spin (the trigger: all of them, twice)
        $bonus = fn (array $grid, array $mults, int $was, int $left) => ['grid' => $grid, 'mults' => $mults, 'variant' => $round['variant'], 'wilds' => $was, 'left' => $left, 'spins' => $n];
        $frames = [];
        $noWins = array_fill(0, count($round['pays']), 0.0);
        $start = $base;
        $startPays = $round['pays'];
        if ($round['lead']) {
            // the triggering board as a plain spin (its wilds go to the meters), then the bonus on a calm board
            $frames[] = $this->frame($cfg, 9, $before, $baseCents, $betIndex, $round['pays'], [0, 0, 0, 0, 0, 0],
                ['grid' => $round['grid'], 'before' => $round['meters_before'], 'after' => $round['meters_landed']], null);
            $start = ['grid' => $this->calm($cfg, $round['grid']), 'before' => $round['meters_landed'], 'after' => $round['meters_after']];
            $startPays = $noWins;
        }
        $frames[] = $this->frame($cfg, $bought ? 66 : 5, $before, $baseCents, $betIndex, $startPays, [$n, $n, 0, 1, 1, 0], $start,
            $bonus($lastBoard ?: $spins[0]['grid'], $empty, $round['wilds'], $round['wilds']));
        $was = $round['wilds'];
        $running = $baseCents;
        $bonusTotal = 0;
        foreach ($spins as $k => $s) {
            $spinCents = $this->cents($s['spin_win'] * $stake);
            $running += $spinCents;
            $bonusTotal += $spinCents;
            $final = $k === $n - 1;
            $frames[] = $this->frame($cfg, $final ? 14 : 6, $final ? $balance : $before, $final ? $this->cents($paid) : $running, $betIndex, $s['pays'],
                [$n, $s['left'], $spinCents, 1, 1, $bonusTotal], ['before' => $start['after']] + $start, $bonus($s['grid'], $s['mults'], $was, $s['wilds_left']));
            $was = $s['wilds_left'];
        }

        return $frames;
    }

    /**
     * WildCat's extended header (40 win slots), then the tail (`hIc`):
     * version 6, a count, two flags; in a bonus its spins, the 5×5 board, its variant,
     * wilds still to come before / after this spin and its wild multipliers; then the
     * base board under its key ("1x20") with the meters before / after.
     *
     * @param  list<float>  $pays
     * @param  array{0:int,1:int,2:int,3:int,4:int,5:int}  $counters
     * @param  array{grid: list<list<int>>, before: list<int>, after: list<int>}  $base
     * @param  ?array{grid: list<list<int>>, mults: list<list<int>>, variant: int, wilds: int, left: int, spins: int}  $bonus
     */
    private function frame(array $cfg, int $code, int $balance, int $win, int $betIndex, array $pays, array $counters, array $base, ?array $bonus): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $wins = array_pad(array_map(fn ($p) => $h(round($p)), $pays), 40, $h(0));
        $cols = fn (array $grid) => $h(count($grid)).implode('', array_map(fn ($c) => $h(count($c)).implode('', array_map(fn ($s) => self::LETTERS[(int) $s], $c)), $grid));
        $out = '-11'.sprintf('%02x', $code).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($balance).$h($win)
            .'05'.str_repeat('10', 5).sprintf('%02x', $betIndex).sprintf('%02x', count((array) $cfg['lines']))
            .implode('', array_map($h, $counters))
            .'05'.str_repeat('10', 5).sprintf('%02x', count($wins)).implode('', $wins).str_repeat('00', 8)
            .$h(6).$h(0).'ff';
        if ($bonus !== null) {
            $out .= $h($bonus['spins']).$cols($bonus['grid']).$h($bonus['variant']).$h($bonus['wilds']).$h($bonus['left']).$cols($bonus['mults']);
        }
        $key = '1x'.count((array) $cfg['lines']);

        return $out.$h(1).$h(strlen($key)).$key.$cols($base['grid']).$this->list($base['before']).$this->list($base['after']).'#101010';
    }

    /** @param list<int> $l */
    private function list(array $l): string
    {
        return $this->fmt->hexFmt(count($l)).implode('', array_map(fn ($v) => $this->fmt->hexFmt($v), $l));
    }

    // ---- config / state ------------------------------------------------

    /** @return list<int> */
    private function meters(array $state, int $betIndex): array
    {
        $cfg = (array) ($this->tableCache['lines'] ?? []);

        return array_values(array_map('intval', (array) (((array) ($state['meters'] ?? []))[$betIndex] ?? $cfg['meter_min'] ?? [5, 20, 50])));
    }

    /** @var array<string, mixed> */
    private array $tableCache = [];

    /** @return array<string, mixed> */
    private function table(GameContext $ctx): array
    {
        return $this->tableCache = (array) ($ctx->config()->bonusConfig()['table'] ?? []);
    }

    /** @return array<string, mixed> */
    private function state(GameContext $ctx): array
    {
        $this->table($ctx);

        return (array) (((array) $ctx->stateGet('features', []))['puffer'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['puffer' => $state]]);
    }

    private function stake(GameContext $ctx, int $index): ?float
    {
        $t = $this->table($ctx);
        $init = $this->initFrame->tail((string) ($t['init'] ?? ''), 1);
        $step = $init['ladder'][$index] ?? null;

        return $step === null ? null : round($step * (int) ($t['bet_factor'] ?? $init['lines']) / 100, 2);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
