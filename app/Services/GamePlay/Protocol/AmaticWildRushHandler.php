<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\SpinDecider;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * WildCat's WildRush on the shared Amatic socket — a base game with memory:
 *
 *   board      6×4, 40 lines; a wild (14) nudges down a row every spin until
 *              it leaves; each reel's light counts the wilds landing on it,
 *              the 3rd turns the reel sticky-wild for 3 spins (light 2→1→0).
 *              The board state is kept per bet level (client key "<ladder>x40").
 *   bonus      3–6 bonus symbols → 10/15/20/25 spins on a fresh board: wilds
 *              land ×2 (15) and gain ×1 per nudge up to ×5 (18); a sticky reel
 *              stays sticky (×1) to the end; a line's ×2+ wilds add up. Played
 *              and settled on the trigger, pulled spin by spin (`A/u256`).
 *
 * States: 9 base · 5 bonus won (66 bought) · 6 bonus spin · 14 last.
 * Line pays are in units of total bet / 20 (validated against live boards).
 */
class AmaticWildRushHandler
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
        $t = $this->table($ctx);
        $init = (string) ($t['init'] ?? '');
        if ($init === '') {
            return $this->fmt->error('A/u25', 'game not configured');
        }
        $state = $this->state($ctx);
        $this->save($ctx, ['queue' => [], 'last' => null] + $state);
        $init = $this->initFrame->withBalance($init, $this->cents($ctx->balance()), 1);
        // the boards kept per bet level join the init's default one: `<count>14null<board>` … `10|…`
        $boards = (array) ($state['boards'] ?? []);
        $at = strrpos($init, '|');
        $open = $at === false ? false : strrpos(substr($init, 0, $at), $this->fmt->hexFmt(4).'null');
        if ($boards === [] || $open === false || $open < 2 || substr($init, $at - 2, 2) !== '10') {
            return $init;
        }
        $extra = '';
        foreach ($boards as $key => $b) {
            $extra .= $this->fmt->hexFmt(strlen((string) $key)).$key.$this->board((array) $b);
        }

        return substr($init, 0, $open - 2).$this->fmt->hexFmt(1 + count($boards)).substr($init, $open, $at - 2 - $open).$extra.substr($init, $at - 2);
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
        [$stake, $key] = $this->stake($ctx, $betIndex);
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
        $options = (array) ($cfg['buy_options'] ?? []);
        $force = $buy >= 0 ? (int) (((array) ($options[$buy] ?? []))['scatters'] ?? $cfg['trigger']) : ($want === 'bonus' ? (int) $cfg['trigger'] : 0);
        $board = $this->boardState((array) (((array) ($state['boards'] ?? []))[$key] ?? []));

        $best = null;
        $bestScore = INF;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $round = $this->round($cfg, $board, $force);
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
        $boards = (array) ($state['boards'] ?? []);
        $boards[$key] = $best['after'];
        $frames = $this->frames($cfg, $best, $betIndex, $stake, $before, $this->cents($ctx->balance()), $paid, $buy >= 0, $boards,
            (array) ($state['bonus_board'] ?? []));
        $ctx->recordRound(
            new SpinResult(bet: $cost, win: $paid, state: $best['bonus'] !== [] ? 'bonus' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => [
                'grid' => $best['after']['cells'], 'baseWin' => $best['win'] * $stake, 'bonusSpins' => count($best['bonus']), 'totalWin' => $paid, 'buy' => $buy,
            ]]),
        );
        if ($best['bonus'] !== []) {
            $state['bonus_board'] = end($best['bonus'])['after']['cells'];
        }
        $first = (string) array_shift($frames);
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'bet_index' => $betIndex, 'boards' => $boards] + $state);

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
     * A base spin on the bet level's board and, when it triggers, its bonus.
     * Wins in total-bet multiples.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array{cells: list<list<int>>, w: list<list<int>>, lights: list<int>, sticky: int}  $board
     * @return array{win: float, pays: list<float>, total: float, after: array<string, mixed>, bonus: list<array<string, mixed>>, scatters: int}
     */
    public function round(array $cfg, array $board, int $forceScatters = 0): array
    {
        $grid = $this->window((array) $cfg['strips'], (int) $cfg['rows']);
        if ($forceScatters > 0) {
            $grid = $this->plantScatters($cfg, $grid, $forceScatters);
        }
        $after = $this->advance($cfg, $board, $grid, false);
        $pays = $this->linePays($cfg, $this->effective($cfg, $after));
        $win = array_sum($pays) / (int) $cfg['bet_factor'];
        $scatters = $this->countIn($after['cells'], (int) $cfg['scatter']);
        $granted = 0;
        foreach ((array) $cfg['free_spins'] as $n => $spins) {
            if ($scatters >= (int) $n) {
                $granted = (int) $spins;
            }
        }
        $bonus = [];
        $bonusWin = 0.0;
        $b = $this->boardState([]);
        // longer bonuses keep fewer of their strips' wilds (sticky reels compound over the spins)
        $keep = (float) (((array) ($cfg['bonus_wild_scale'] ?? []))[$granted] ?? 1.0);
        for ($k = 0; $k < $granted; $k++) {
            $grid = $this->window((array) $cfg['bonus_strips'], (int) $cfg['rows']);
            foreach ($grid as $r => $col) {
                foreach ($col as $row => $s) {
                    if ($s === (int) $cfg['wild'] && random_int(0, 999999) >= $keep * 1000000) {
                        $grid[$r][$row] = random_int(9, 12);
                    }
                }
            }
            $b = $this->advance($cfg, $b, $grid, true);
            $spinPays = $this->linePays($cfg, $this->effective($cfg, $b));
            $spinWin = array_sum($spinPays) / (int) $cfg['bet_factor'];
            $bonusWin += $spinWin;
            $bonus[] = ['after' => $b, 'pays' => $spinPays, 'spin_win' => $spinWin, 'left' => $granted - $k - 1];
        }

        return ['win' => $win, 'pays' => $pays, 'total' => round($win + $bonusWin, 4), 'after' => $after, 'bonus' => $bonus, 'scatters' => $scatters];
    }

    /**
     * One spin's board state: the wilds on it nudge down a row (in a bonus
     * gaining ×1, to the cap), wilds landing on non-sticky reels light the
     * reel, the `sticky_after`-th makes it sticky — for `sticky_spins` spins
     * (light 2→1→0), in a bonus for good.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array{cells: list<list<int>>, w: list<list<int>>, lights: list<int>, sticky: int}  $prev
     * @param  list<list<int>>  $grid  the strips' window
     * @return array{cells: list<list<int>>, w: list<list<int>>, lights: list<int>, sticky: int}
     */
    private function advance(array $cfg, array $prev, array $grid, bool $bonus): array
    {
        $rows = (int) $cfg['rows'];
        $wild = (int) $cfg['wild'];
        $reels = count($grid);
        $w = array_fill(0, $reels, array_fill(0, $rows, 0));
        $lights = $prev['lights'];
        $sticky = $prev['sticky'];
        $cells = $grid;
        for ($r = 0; $r < $reels; $r++) {
            if ($prev['sticky'] >> $r & 1) {
                if (! $bonus) {                                 // the countdown; released after 0
                    if ($prev['lights'][$r] <= 0) {
                        $sticky &= ~(1 << $r);
                        $lights[$r] = 0;
                    } else {
                        $lights[$r] = $prev['lights'][$r] - 1;
                    }
                }
                foreach ($cells[$r] as $row => $s) {           // a sticky reel shows no wild of its own
                    if ($s === $wild) {
                        $cells[$r][$row] = random_int(5, 12);
                    }
                }

                continue;
            }
            foreach ($prev['w'][$r] as $row => $v) {            // nudge: the cell below now holds the wild
                if ($v > 0 && $row < $rows - 1) {
                    $w[$r][$row + 1] = $bonus ? min((int) $cfg['wild_max'], $v + 1) : $wild;
                    $cells[$r][$row + 1] = 0;
                }
            }
            $landed = 0;
            foreach ($cells[$r] as $row => $s) {
                if ($s === $wild) {
                    $w[$r][$row] = $bonus ? (int) $cfg['bonus_wild'] : $wild;
                    $landed++;
                }
            }
            $lights[$r] += $landed;
            if ($lights[$r] >= (int) $cfg['sticky_after']) {
                $sticky |= 1 << $r;
                $lights[$r] = (int) $cfg['sticky_spins'] - 1;
                $w[$r] = array_fill(0, $rows, 0);
            }
        }

        return ['cells' => $cells, 'w' => $w, 'lights' => $lights, 'sticky' => $sticky];
    }

    /**
     * What the lines see: the wild layer over the cells, sticky reels all wild (×1).
     *
     * @param  array<string, mixed>  $cfg
     * @param  array{cells: list<list<int>>, w: list<list<int>>, lights: list<int>, sticky: int}  $b
     * @return list<list<int>>
     */
    private function effective(array $cfg, array $b): array
    {
        $grid = $b['cells'];
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $s) {
                if ($b['sticky'] >> $r & 1) {
                    $grid[$r][$row] = (int) $cfg['wild'];
                } elseif ($b['w'][$r][$row] > 0) {
                    $grid[$r][$row] = $b['w'][$r][$row];
                }
            }
        }

        return $grid;
    }

    /**
     * Each line's best left-to-right pay (wilds substitute; the ×2+ wilds on
     * the run add their multipliers, else ×1).
     *
     * @param  array<string, mixed>  $cfg
     * @return list<float>
     */
    private function linePays(array $cfg, array $grid): array
    {
        $wilds = array_map('intval', (array) $cfg['substitutes']);
        $base = (int) $cfg['wild'];
        $scatter = (int) $cfg['scatter'];
        $pays = (array) $cfg['pays'];
        $out = [];
        foreach ((array) $cfg['lines'] as $line) {
            $syms = [];
            foreach ($line as $r => $row) {
                $syms[] = (int) $grid[$r][$row];
            }
            $best = 0.0;
            foreach (array_unique(array_merge(array_filter($syms, fn ($s) => ! in_array($s, $wilds, true) && $s !== $scatter), [(int) $cfg['top']])) as $sym) {
                $n = 0;
                $mult = 0;
                foreach ($syms as $s) {
                    if ($s !== $sym && ! in_array($s, $wilds, true)) {
                        break;
                    }
                    $n++;
                    if ($s !== $base && in_array($s, $wilds, true)) {
                        $mult += $s - $base + 1;
                    }
                }
                $best = max($best, (float) (((array) ($pays[$sym] ?? []))[$n - 1] ?? 0) * max(1, $mult));
            }
            $out[] = $best;
        }

        return $out;
    }

    /** @param array<string, mixed> $cfg */
    private function plantScatters(array $cfg, array $grid, int $n): array
    {
        $scatter = (int) $cfg['scatter'];
        $have = $this->countIn($grid, $scatter);
        $reels = array_keys(array_filter($grid, fn ($col) => ! in_array($scatter, $col, true)));
        shuffle($reels);
        foreach (array_slice($reels, 0, max(0, $n - $have)) as $r) {
            $grid[$r][random_int(0, (int) $cfg['rows'] - 1)] = $scatter;
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

    // ---- frames ----------------------------------------------------

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $round
     * @param  array<string, mixed>  $boards  every bet level's board (the map)
     * @return list<string>
     */
    private function frames(array $cfg, array $round, int $betIndex, float $stake, int $before, int $balance, float $paid, bool $bought, array $boards, array $lastBonus): array
    {
        $baseCents = $this->cents($round['win'] * $stake);
        if ($round['bonus'] === []) {
            return [$this->frame($cfg, 9, $balance, $baseCents, $betIndex, $round['pays'], [0, 0, 0, 0, 0, 0], null, $boards)];
        }
        $n = count($round['bonus']);
        $fresh = $this->boardState([]);
        $start = ['cells' => $lastBonus ?: $round['bonus'][0]['after']['cells']] + $fresh;
        $frames = [$this->frame($cfg, $bought ? 66 : 5, $before, $baseCents, $betIndex, $round['pays'], [$n, $n, 0, 1, 1, 0], [$n, $start], $boards)];
        $running = $baseCents;
        $bonusTotal = 0;
        foreach ($round['bonus'] as $k => $s) {
            $spinCents = $this->cents($s['spin_win'] * $stake);
            $running += $spinCents;
            $bonusTotal += $spinCents;
            $final = $k === $n - 1;
            $frames[] = $this->frame($cfg, $final ? 14 : 6, $final ? $balance : $before, $final ? $this->cents($paid) : $running, $betIndex, $s['pays'],
                [$n, $s['left'], $spinCents, 1, 1, $bonusTotal], [$n, $s['after']], $final ? $boards : []);
        }

        return $frames;
    }

    /**
     * WildCat's extended header (40 win slots), then the tail (`e9Y`):
     * version 5, a count, two flags; in a bonus its spins and board; then the
     * boards kept per bet level under their keys.
     *
     * @param  list<float>  $pays
     * @param  array{0:int,1:int,2:int,3:int,4:int,5:int}  $counters
     * @param  ?array{0: int, 1: array<string, mixed>}  $bonus
     * @param  array<string, mixed>  $boards
     */
    private function frame(array $cfg, int $code, int $balance, int $win, int $betIndex, array $pays, array $counters, ?array $bonus, array $boards): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $wins = array_pad(array_map(fn ($p) => $h(round($p)), $pays), 40, $h(0));
        $out = '-11'.sprintf('%02x', $code).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($balance).$h($win)
            .'06'.str_repeat('10', 6).sprintf('%02x', $betIndex).sprintf('%02x', count((array) $cfg['lines']))
            .implode('', array_map($h, $counters))
            .'06'.str_repeat('10', 6).sprintf('%02x', count($wins)).implode('', $wins).str_repeat('00', 8)
            .$h(5).$h(0).'ff';
        if ($bonus !== null) {
            $out .= $h($bonus[0]).$this->board($bonus[1]);
        }
        $out .= $h(count($boards));
        foreach ($boards as $key => $b) {
            $out .= $h(strlen((string) $key)).$key.$this->board((array) $b);
        }

        return $out.'#101010';
    }

    /** A board: cells, wild layer (letter columns), the lights (letters), the sticky reels (a hex bitmask, empty for none). */
    private function board(array $b): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $col = fn (array $c) => $h(count($c)).implode('', array_map(fn ($s) => self::LETTERS[max(0, (int) $s)], $c));
        $sticky = (int) ($b['sticky'] ?? 0);
        $mask = $sticky > 0 ? sprintf('%02x', $sticky) : '';

        return $h(count($b['cells'])).implode('', array_map($col, $b['cells']))
            .$h(count($b['w'])).implode('', array_map($col, $b['w']))
            .$col($b['lights'])
            .$h(strlen($mask)).$mask;
    }

    // ---- config / state ------------------------------------------------

    /**
     * @param  array<string, mixed>  $b
     * @return array{cells: list<list<int>>, w: list<list<int>>, lights: list<int>, sticky: int}
     */
    private function boardState(array $b): array
    {
        return [
            'cells' => (array) ($b['cells'] ?? array_fill(0, 6, array_fill(0, 4, 0))),
            'w' => (array) ($b['w'] ?? array_fill(0, 6, array_fill(0, 4, 0))),
            'lights' => array_map('intval', (array) ($b['lights'] ?? array_fill(0, 6, 0))),
            'sticky' => (int) ($b['sticky'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function table(GameContext $ctx): array
    {
        return (array) ($ctx->config()->bonusConfig()['table'] ?? []);
    }

    /** @return array<string, mixed> */
    private function state(GameContext $ctx): array
    {
        return (array) (((array) $ctx->stateGet('features', []))['wildrush'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['wildrush' => $state]]);
    }

    /** @return array{0: ?float, 1: string} the stake and the bet level's board key ("<ladder>x<lines>") */
    private function stake(GameContext $ctx, int $index): array
    {
        $t = $this->table($ctx);
        $init = $this->initFrame->tail((string) ($t['init'] ?? ''), 1);
        $step = $init['ladder'][$index] ?? null;

        return [
            $step === null ? null : round($step * (int) ($t['bet_factor'] ?? $init['coins']) / 100, 2),
            ($step ?? 0).'x'.count((array) (((array) ($t['lines'] ?? []))['lines'] ?? [])),
        ];
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
