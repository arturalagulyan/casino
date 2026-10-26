<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Amatic\AmaticPiggyHoldWin;
use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\SpinDecider;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * WildCat Gaming line slots (MerlinsBookOfDragons, …) on the shared Amatic
 * socket: the classic gmsl spin frame with WildCat's extended header
 * (`-1` + reel counts), each game's own tail after the cards.
 *
 *   base game  a window of Amatic's own strips (shipped in the init), fixed
 *              lines paying left to right, substitutes (wild / book)
 *   bonus      3+ scatters → bonus spins (+ the same again during them); the
 *              whole bonus is played and settled on the triggering spin and
 *              pulled spin by spin (`A/u256`); the balance moves on its last
 *
 *   features   `book_gems` (Merlin): a random expanding symbol per bonus,
 *              GEM symbols on the gem reels mark their reel for good; after
 *              the line wins the expanding symbol (gem reels counting) pays on
 *              every line by the number of reels holding it
 *
 * States: 9 base · 5 bonus won (66 bought) · 6 bonus spin · 10 more spins won · 14 last.
 * Outcome type is gated by {@see SpinDecider} like every slot.
 */
class AmaticWildcatLinesHandler
{
    private const int MAX_TRIES = 300;

    public function __construct(
        private readonly AmaticFormatter $fmt,
        private readonly AmaticWildcatInit $initFrame,
        private readonly SpinDecider $decider,
        private readonly AmaticPiggyHoldWin $hold,
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
            'A/u256', 'A/u2531', 'A/u2546', 'A/u2553' => [$this->next($ctx)],
            'A/u2517' => [$this->choose($ctx, (int) ($parts[1] ?? 0))],
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
        // bonus spins left over from a closed session were paid with their trigger
        $this->save($ctx, ['queue' => [], 'last' => null] + $this->state($ctx));

        return $this->initFrame->withBalance($init, $this->cents($ctx->balance()), 1);
    }

    /**
     * A base spin — or, with `$buy` ≥ 0, the bought bonus (price `buy[$buy]` × stake).
     * `$booster`: PiggyPots' COIN BOOSTER (stake × `booster_price`, the bonus twice as likely).
     */
    private function spin(GameContext $ctx, int $betIndex, int $buy = -1, bool $booster = false): string
    {
        $state = $this->state($ctx);
        if (($state['queue'] ?? []) !== []) {
            return $this->next($ctx);
        }
        $t = $this->table($ctx);
        $cfg = (array) $t['lines'];
        $stake = $this->stake($ctx, $betIndex);
        $prices = array_values((array) ($t['buy'] ?? []));
        $booster = $booster && $buy < 0 && (float) ($cfg['booster_price'] ?? 0) > 0;
        $price = $buy >= 0 ? (float) ($prices[$buy] ?? 0) : ($booster ? (float) $cfg['booster_price'] : 1.0);
        if ($stake === null || $price <= 0) {
            return $this->fmt->error($buy >= 0 ? 'A/u2566' : 'A/u251', 'invalid bet');
        }
        $cost = round($stake * $price, 2);
        if ($ctx->balance() < $cost) {
            return $this->fmt->error($buy >= 0 ? 'A/u2566' : 'A/u251', 'invalid balance');
        }
        $ctx->placeBet($cost);

        $decision = $this->decider->decide($ctx, 'spin', count((array) $cfg['lines']), $stake, $booster ? (float) ($cfg['booster_boost'] ?? 2.0) : 1.0);
        $want = $buy >= 0 ? 'bonus' : $decision->type;
        $ceiling = min($decision->budget, $stake * max(1.0, $decision->maxWinMultiplier));
        if ($buy >= 0) {
            $ceiling = max($ceiling, $cost * 0.2);
        }
        $boost = $buy >= 0 ? (array) (((array) ($cfg['buy_options'] ?? []))[$buy] ?? []) : [];
        if (($cfg['feature'] ?? '') === 'piggy') {
            // a bought / gated bonus is a Hold & Win, bonus spins or the CASHPOT Hold & Win
            if ($buy < 0) {
                $boost = match ($want === 'bonus' ? (string) $this->pick((array) $cfg['bonus_mix']) : '') {
                    'holdwin' => ['holdwin' => true, 'natural' => true],
                    'cashpot' => ['holdwin' => true, 'cashpot' => true, 'natural' => true],
                    default => [],
                };
            }
            $range = array_map('intval', (array) ($boost['scatters'] ?? [$cfg['trigger'], $cfg['trigger']]));
            $force = isset($boost['holdwin']) || ($buy < 0 && $want !== 'bonus') ? 0 : random_int($range[0], $range[1] ?? $range[0]);
        } else {
            $force = $buy >= 0 ? (int) ($boost['scatters'] ?? $cfg['trigger']) : ($want === 'bonus' ? (int) $cfg['trigger'] : 0);
        }

        $best = null;
        $bestScore = INF;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $round = $this->round($cfg, $force, $boost);
            $win = $round['total'] * $stake;
            // (PiggyPots: when bonus spins were asked for, a Hold & Win the board started isn't them)
            $triggered = $round['bonus'] !== [] || $round['choice'] !== null || (($round['holdwin'] ?? null) !== null && $force === 0);
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
        $frames = ($cfg['feature'] ?? '') === 'piggy'
            ? $this->piggyFrames($cfg, $best, $betIndex, $stake, $before, $this->cents($ctx->balance()), $paid, $buy >= 0, $state)
            : $this->frames($ctx, $best, $betIndex, $stake, $before, $paid, $buy >= 0, $state);
        $ctx->recordRound(
            new SpinResult(bet: $cost, win: $paid, state: $best['bonus'] !== [] || ($best['holdwin'] ?? null) !== null ? 'bonus' : 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => [
                'grid' => $best['grid'], 'baseWin' => $best['win'] * $stake, 'bonusSpins' => count($best['bonus']), 'totalWin' => $paid, 'buy' => $buy,
            ]]),
        );
        $first = (string) array_shift($frames);
        $pending = $best['choice'] !== null
            ? ['scatters' => $best['choice'], 'ceiling' => $ceiling, 'stake' => $stake, 'grid' => $best['grid'], 'pays' => $best['pays'], 'base_cents' => $this->cents($paid)]
            : null;
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'bet_index' => $betIndex, 'pending' => $pending]
            + $this->remember($cfg, $best, $stake, $state));

        return $first;
    }

    /** Cleo: the bonus the player picked after a trigger (`A/u2517,<index>`), played and settled now. */
    private function choose(GameContext $ctx, int $index): string
    {
        $state = $this->state($ctx);
        $pending = (array) ($state['pending'] ?? []);
        if ($pending === []) {
            return $this->fmt->error('A/u2517', 'no bonus to choose');
        }
        $cfg = (array) $this->table($ctx)['lines'];
        $stake = (float) $pending['stake'];
        $ceiling = (float) $pending['ceiling'];
        $best = null;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $bonus = $this->choiceBonus($cfg, (int) $pending['scatters'], $index);
            if ($best === null || $bonus['total'] < $best['total']) {
                $best = $bonus;
            }
            if ($bonus['total'] * $stake <= $ceiling) {
                $best = $bonus;
                break;
            }
        }
        /** @var array{spins: list<array<string, mixed>>, total: float, granted: int} $best */
        $before = $this->cents($ctx->balance());
        $won = round($best['total'] * $stake, 2);
        $paid = $won > 0 ? $ctx->awardWin($won) : 0.0;
        $ctx->recordRound(
            new SpinResult(bet: 0.0, win: $paid, state: 'freespin'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['choice' => $index, 'spins' => $best['granted'], 'totalWin' => $paid]]),
        );
        $round = ['grid' => $pending['grid'], 'pays' => $pending['pays'], 'win' => 0.0, 'pot' => 0.0, 'mult' => 1, 'special' => -1,
            'granted' => $best['granted'], 'bonus' => $best['spins'], 'start_marked' => [], 'wheel' => [], 'respin' => null, 'choice' => null,
            'eval' => ['grid' => $pending['grid']]];
        $state['cleo'] = ['collect' => (int) ($state['cleo']['collect'] ?? 0), 'choice' => $index, 'spins' => $best['granted']];
        $frames = $this->frames($ctx, $round, (int) ($state['bet_index'] ?? 0), $stake, $before, $paid + (int) $pending['base_cents'] / 100, false, $state);
        $first = (string) array_shift($frames);
        $this->save($ctx, ['queue' => $frames, 'last' => $first, 'pending' => null] + $this->remember($cfg, ['bonus' => $best['spins']], $stake, $state) + $state);

        return $first;
    }

    /**
     * Cleo's tail repeats the last collect (cents), the last bonus choice and
     * its spins on every frame — kept per session.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $round
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function remember(array $cfg, array $round, float $stake, array $state): array
    {
        if (($cfg['tail'] ?? '') === 'piggy') {
            $pig = (array) ($state['piggy'] ?? ['coins' => 0, 'collect' => 0]);
            foreach ($this->piggyBoards($round) as $s) {
                $pig['coins'] = (int) $pig['coins'] + (int) ($s['piggy']['coins'] ?? 0);
                if ((float) ($s['piggy']['collect'] ?? 0) > 0) {
                    $pig['collect'] = $this->cents((float) $s['piggy']['collect'] * $stake);
                }
            }

            return ['piggy' => $pig];
        }
        if (($cfg['tail'] ?? '') !== 'cleo') {
            return [];
        }
        $cleo = (array) ($state['cleo'] ?? ['collect' => 0, 'choice' => 0, 'spins' => 0]);
        foreach (array_merge([$round['eval'] ?? []], (array) ($round['bonus'] ?? [])) as $s) {
            $collect = (float) ($s['coins']['collect'] ?? 0);
            if ($collect > 0) {
                $cleo['collect'] = $this->cents($collect * $stake);
            }
        }

        return ['cleo' => $cleo];
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
     * A base spin and, when it triggers, its whole bonus. Wins in total-bet
     * multiples, line pays in line-bet multiples.
     *
     * @param  array<string, mixed>  $cfg
     * @param  int  $forceScatters  land at least this many scatters (a gated / bought bonus)
     * @param  array<string, mixed>  $boost  a bought option: `start_gems` [min, max], `gem` (one guaranteed)
     * @return array{grid: list<list<int>>, pays: list<float>, win: float, pot: float, scatters: int, bonus: list<array<string, mixed>>, total: float, special: int, granted: int, start_marked: list<bool>, wheel: list<array{0: int, 1: int}>, mult: int, respin: ?array<string, mixed>, choice: ?int, eval: array<string, mixed>, holdwin?: ?array<string, mixed>, cashpot?: bool}
     */
    public function round(array $cfg, int $forceScatters = 0, array $boost = []): array
    {
        if (($cfg['feature'] ?? '') === 'piggy') {
            return $this->piggyRound($cfg, $forceScatters, $boost);
        }
        $base = $this->spinGrid($cfg, (array) $cfg['strips'], $forceScatters);
        // Cleo: collectors on the first and last reel without coins → a respin of the reels between
        $respin = null;
        if (($cfg['feature'] ?? '') === 'coins' && $forceScatters === 0) {
            [$base, $respin] = $this->collectRespin($cfg, $base);
        }
        $scatters = $this->count($base, (int) $cfg['scatter']);
        $granted = $this->freeSpinsFor($cfg, $scatters);
        // Cleo: the player picks the bonus first — it is played on `A/u2517`
        if ($granted > 0 && isset($cfg['choices'])) {
            return [
                'grid' => $base['grid'], 'pays' => $base['pays'], 'win' => $base['win'] + $base['pot'], 'pot' => $base['pot'], 'scatters' => $scatters,
                'bonus' => [], 'total' => round($base['win'] + $base['pot'], 4), 'special' => -1, 'granted' => 0,
                'start_marked' => [], 'wheel' => [], 'mult' => 1, 'respin' => $respin, 'choice' => $scatters, 'eval' => $base,
            ];
        }
        $bonus = [];
        $special = -1;
        $bonusWin = 0.0;
        // Rampage: the bonus wheel — 10 (spins, multiplier) pairs, one of them awarded
        $wheel = [];
        $mult = 1;
        if ($granted > 0 && ($cfg['bonus_award'] ?? '') === 'wheel') {
            $wheel = $this->wheel($cfg, $scatters);
            [$granted, $mult] = $wheel[array_rand($wheel)];
            if (isset($boost['spins'])) {   // a bought bonus: its spins are fixed, the wheel only picks the multiplier
                $granted = (int) $boost['spins'];
                $wheel = array_map(fn ($p) => [$granted, $p[1]], $wheel);
            }
        }
        if ($granted > 0) {
            $feature = (string) ($cfg['feature'] ?? '');
            $special = $feature === 'book_gems' ? (int) ((array) $cfg['expanding'])[array_rand((array) $cfg['expanding'])] : -1;
            $marked = array_fill(0, count($base['grid']), false);
            // bought: gems already active at the start / one guaranteed during the bonus
            $gemReels = array_map('intval', (array) ($cfg['gem_reels'] ?? []));
            if (isset($boost['start_gems']) && $gemReels !== []) {
                shuffle($gemReels);
                foreach (array_slice($gemReels, 0, random_int(...array_map('intval', (array) $boost['start_gems']))) as $r) {
                    $marked[$r] = true;
                }
            }
            $startMarked = $marked;
            $gemAt = (int) ($boost['gem'] ?? 0) > 0 ? random_int(0, max(0, intdiv($granted, 2))) : -1;
            $left = $granted;
            $total = $granted;
            // EpicCatch: every 3rd wild queues 10 more spins at the next multiplier, played after these
            $pending = [];
            $curMult = $mult;
            $wildsSeen = 0;
            $unlocked = 0;
            while (($left > 0 || $pending !== []) && count($bonus) < 500) {
                if ($left <= 0) {
                    [$left, $curMult] = array_shift($pending);
                }
                $s = $this->spinGrid(['wild_rate' => $cfg['bonus_wild_rate'] ?? $cfg['wild_rate'] ?? 0] + $cfg, (array) ($cfg['bonus_strips'] ?? $cfg['strips']));
                $left--;
                if ($feature === 'fish') {
                    $wildsSeen += $this->count($s, (int) $cfg['wild']);
                    $levels = array_values((array) $cfg['wild_levels']);
                    while (intdiv($wildsSeen, 3) > $unlocked && $unlocked < count($levels)) {
                        $pending[] = [(int) $cfg['level_spins'], (int) $levels[$unlocked]];
                        $total += (int) $cfg['level_spins'];
                        $unlocked++;
                    }
                }
                $expanding = 0.0;
                if ($feature === 'book_gems') {
                    if (count($bonus) === $gemAt && ($open = array_values(array_filter($gemReels, fn ($r) => ! $marked[$r]))) !== []) {
                        $s['grid'][$open[array_rand($open)]][random_int(0, (int) $cfg['rows'] - 1)] = (int) $cfg['gem'];
                        $s = ['grid' => $s['grid']] + $this->evaluate($cfg, $s['grid']);
                    }
                    foreach ($s['grid'] as $r => $col) {
                        if (in_array((int) $cfg['gem'], $col, true)) {
                            $marked[$r] = true;
                        }
                    }
                    $expanding = $this->expandingPay($cfg, $s['grid'], $special, $marked);
                }
                $more = $this->freeSpinsFor($cfg, $this->count($s, (int) $cfg['scatter']));
                if ($more > 0 && $wheel !== []) {
                    $more = $this->wheel($cfg, $this->count($s, (int) $cfg['scatter']))[0][0];   // spins only: the multiplier stays locked
                }
                $left += $more;
                $total += $more;
                $spinWin = $s['win'] * $curMult + $s['pot'] + $expanding;
                $bonusWin += $spinWin;
                $bonus[] = $s + ['expanding' => $expanding, 'spin_win' => $spinWin, 'marked' => $marked, 'more' => $more, 'left' => $left + array_sum(array_column($pending, 0)), 'total' => $total,
                    'spin_mult' => $curMult, 'wilds_seen' => $wildsSeen];
            }
        }

        return [
            'grid' => $base['grid'], 'pays' => $base['pays'], 'win' => $base['win'] + $base['pot'], 'pot' => $base['pot'], 'scatters' => $scatters,
            'bonus' => $bonus, 'total' => round($base['win'] + $base['pot'] + $bonusWin, 4), 'special' => $special, 'granted' => $granted,
            'start_marked' => $startMarked ?? [], 'wheel' => $wheel, 'mult' => $mult, 'respin' => $respin, 'choice' => null, 'eval' => $base,
        ];
    }

    /**
     * Cleo's bonus, once picked: `choices[$index]` — bonus spins on strips
     * without the low symbols, or hyperspins (only coins and collectors land).
     *
     * @param  array<string, mixed>  $cfg
     * @return array{spins: list<array<string, mixed>>, total: float, granted: int}
     */
    public function choiceBonus(array $cfg, int $scatters, int $index): array
    {
        $choice = (array) (((array) $cfg['choices'])[$index] ?? ((array) $cfg['choices'])[0]);
        $granted = 0;
        foreach ((array) $choice['spins'] as $count => $n) {
            if ($scatters >= (int) $count) {
                $granted = (int) $n;
            }
        }
        $spins = [];
        $sum = 0.0;
        for ($k = 0; $k < $granted; $k++) {
            $s = ($choice['mode'] ?? '') === 'hyper'
                ? $this->hyperGrid($cfg)
                : $this->spinGrid(['pot_keep' => $cfg['bonus_pot_keep'] ?? $cfg['pot_keep'] ?? 1.0] + $cfg, (array) ($cfg['bonus_strips'] ?? $cfg['strips']));
            $sum += $s['win'] + $s['pot'];
            $spins[] = $s + ['spin_win' => $s['win'] + $s['pot'], 'left' => $granted - $k - 1, 'total' => $granted, 'more' => 0, 'expanding' => 0.0, 'marked' => []];
        }

        return ['spins' => $spins, 'total' => round($sum, 4), 'granted' => $granted];
    }

    /**
     * A hyperspin board: only empty cells, then 1–2 collectors on the collector
     * reels and at least two coins (sometimes more) on the others.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{grid: list<list<int>>, pays: list<float>, win: float, pot: float}
     */
    private function hyperGrid(array $cfg): array
    {
        $rows = (int) $cfg['rows'];
        $reels = count((array) $cfg['strips']);
        $grid = array_fill(0, $reels, array_fill(0, $rows, (int) $cfg['empty']));
        $collectorReels = array_map('intval', (array) $cfg['collector_reels']);
        foreach ((array) array_rand(array_flip($collectorReels), $this->chance(0.3) ? min(2, count($collectorReels)) : 1) as $r) {
            $grid[(int) $r][random_int(0, $rows - 1)] = (int) $cfg['collector'];
        }
        $cells = [];
        foreach (array_diff(range(0, $reels - 1), $collectorReels) as $r) {
            for ($row = 0; $row < $rows; $row++) {
                $cells[] = [$r, $row];
            }
        }
        shuffle($cells);
        $coins = 2 + (int) floor(-log(max(1e-9, random_int(1, 1000000) / 1000000)) * 0.6);
        foreach (array_slice($cells, 0, min(count($cells), $coins)) as [$r, $row]) {
            $grid[$r][$row] = $this->chance((float) ($cfg['hyper_pot'] ?? 0.004)) ? (int) array_rand((array) $cfg['jackpots']) : (int) $cfg['coin'];
        }

        return ['grid' => $grid] + $this->evaluate($cfg, $grid);
    }

    /**
     * Cleo's respin: a board with collectors on both the first and the last
     * reel but no coin holds those reels and respins the others with at least
     * two coins.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $base
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function collectRespin(array $cfg, array $base): array
    {
        $grid = $base['grid'];
        $last = count($grid) - 1;
        $collector = (int) $cfg['collector'];
        $coinSyms = array_merge([(int) $cfg['coin']], array_map('intval', array_keys((array) ($cfg['jackpots'] ?? []))));
        if (! in_array($collector, $grid[0], true) || ! in_array($collector, $grid[$last], true)
            || array_intersect($coinSyms, array_merge(...$grid)) !== []) {
            return [$base, null];
        }
        $strips = array_values((array) $cfg['strips']);
        $rows = (int) $cfg['rows'];
        for ($r = 1; $r < $last; $r++) {
            $at = random_int(0, count($strips[$r]) - 1);
            for ($row = 0; $row < $rows; $row++) {
                $grid[$r][$row] = (int) $strips[$r][($at + $row) % count($strips[$r])];
            }
        }
        $cells = [];
        for ($r = 1; $r < $last; $r++) {
            for ($row = 0; $row < $rows; $row++) {
                $cells[] = [$r, $row];
            }
        }
        shuffle($cells);
        $have = count(array_filter(array_merge(...array_slice($grid, 1, $last - 1)), fn ($s) => in_array($s, $coinSyms, true)));
        foreach (array_slice($cells, 0, max(0, (int) ($cfg['respin_min_coins'] ?? 2) - $have)) as [$r, $row]) {
            $grid[$r][$row] = (int) $cfg['coin'];
        }

        return [['grid' => $grid] + $this->evaluate($cfg, $grid), $base];
    }

    /**
     * Cleo's coins on a board: values (× total bet) for every coin — cash-pot
     * coins at their pot — ADD then MULTIPLY applied to the plain coins, and
     * what the collectors bank (each collector takes them all).
     *
     * @param  array<string, mixed>  $cfg
     * @return array{values: list<list<float>>, add: float, multiply: int, collect: float}
     */
    private function coins(array $cfg, array $grid): array
    {
        $jackpots = (array) $cfg['jackpots'];
        $add = in_array((int) $cfg['add'], array_merge(...$grid), true) ? (float) $this->pick((array) $cfg['add_values']) / 10 : 0.0;
        $multiply = in_array((int) $cfg['multiply'], array_merge(...$grid), true) ? (int) $this->pick((array) $cfg['multiply_values']) : 1;
        $values = [];
        $sum = 0.0;
        $collectors = 0;
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $sym) {
                $v = 0.0;
                if ($sym === (int) $cfg['coin']) {
                    $v = ((float) $this->pick((array) $cfg['coin_values']) / 10 + $add) * $multiply;
                } elseif (isset($jackpots[$sym])) {
                    $v = (float) $jackpots[$sym];
                } elseif ($sym === (int) $cfg['collector']) {
                    $collectors++;
                }
                $values[$r][$row] = $v;
                $sum += $v;
            }
        }

        return ['values' => $values, 'add' => $add, 'multiply' => $multiply, 'collect' => $sum * $collectors];
    }

    /** @param array<int|string, int|float> $weights value => weight */
    private function pick(array $weights): int|string
    {
        $total = array_sum($weights);
        $x = random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX * $total;
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

    /**
     * Rampage's bonus wheel: 10 (spins, multiplier) pairs drawn for this many
     * bonus symbols — more symbols, better pairs.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function wheel(array $cfg, int $scatters): array
    {
        $spins = array_values(array_map('intval', (array) $cfg['wheel_spins']));
        $mults = array_values(array_map('intval', (array) $cfg['wheel_multipliers']));
        $lift = max(0, $scatters - (int) $cfg['trigger']);   // each extra symbol shifts the draw up
        $pairs = [];
        for ($k = 0; $k < 10; $k++) {
            $s = $spins[min(count($spins) - 1, (int) floor((random_int(0, 999) / 1000) ** (1 + 0.6 * (2 - min(2, $lift))) * count($spins)))];
            $m = $mults[min(count($mults) - 1, (int) floor((random_int(0, 999) / 1000) ** (2 + 0.8 * (2 - min(2, $lift))) * count($mults)))];
            $pairs[] = [$s, $m];
        }

        return $pairs;
    }

    /**
     * A window of the strips and its line pays.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<int, list<int>>  $strips
     * @return array{grid: list<list<int>>, pays: list<float>, win: float, pot: float, piggy?: array{values: array<int, array<int, float>>, collect: float, coins: int}, coins?: array<string, mixed>, fish?: array<string, mixed>}
     */
    private function spinGrid(array $cfg, array $strips, int $forceScatters = 0): array
    {
        $rows = (int) $cfg['rows'];
        $scatter = (int) $cfg['scatter'];
        $grid = [];
        foreach (array_values($strips) as $r => $strip) {
            $at = random_int(0, count($strip) - 1);
            for ($row = 0; $row < $rows; $row++) {
                $grid[$r][$row] = (int) $strip[($at + $row) % count($strip)];
            }
        }
        // EpicCatch: the server drops its wilds onto the board itself (its strips carry none)
        if ((float) ($cfg['wild_rate'] ?? 0) > 0) {
            foreach ((array) $cfg['wild_reels'] as $r) {
                for ($row = 0; $row < $rows; $row++) {
                    if ($this->chance((float) $cfg['wild_rate'])) {
                        $grid[(int) $r][$row] = (int) $cfg['wild'];
                    }
                }
            }
        }
        // a gated / bought bonus: plant the missing scatters on reels without one
        $have = $this->countIn($grid, $scatter);
        if ($have < $forceScatters) {
            $reels = array_keys(array_filter($grid, fn ($col) => ! in_array($scatter, $col, true)));
            shuffle($reels);
            foreach (array_slice($reels, 0, $forceScatters - $have) as $r) {
                $grid[$r][random_int(0, $rows - 1)] = $scatter;
            }
        }
        if (($cfg['feature'] ?? '') === 'coins') {
            $grid = $this->thinPots($cfg, $grid);
        }

        return ['grid' => $grid] + $this->evaluate($cfg, $grid);
    }

    /**
     * Lines (a pay per line) or ways (a pay per symbol, `ways_unit` per total
     * bet), plus a scatter pot (Rampage's golden shields) in total-bet multiples.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{pays: list<float>, win: float, pot: float}
     */
    private function evaluate(array $cfg, array $grid): array
    {
        $pot = 0.0;
        if (isset($cfg['pot'])) {
            $n = $this->countIn($grid, (int) $cfg['pot']['symbol']);
            foreach ((array) $cfg['pot']['pays'] as $count => $x) {
                if ($n >= (int) $count) {
                    $pot = (float) $x;
                }
            }
        }
        // Cleo: what the collectors bank joins the spin's win
        $coins = ($cfg['feature'] ?? '') === 'coins' ? $this->coins($cfg, $grid) : null;
        $extra = $coins === null ? [] : ['coins' => $coins];
        $collect = $coins['collect'] ?? 0.0;
        // PiggyPots: coin values (× bet), each collector banks them all
        if (($cfg['feature'] ?? '') === 'piggy') {
            $values = [];
            $sum = 0.0;
            $collectors = 0;
            $n = 0;
            foreach ($grid as $r => $col) {
                foreach ($col as $row => $sym) {
                    $v = 0.0;
                    if ($sym === (int) $cfg['coin']) {
                        $v = (float) $this->pick((array) $cfg['coin_values']);
                        $sum += $v;
                        $n++;
                    } elseif ($sym === (int) $cfg['collector']) {
                        $v = -1.0;
                        $collectors++;
                    }
                    $values[$r][$row] = $v;
                }
            }
            $collect = $sum * $collectors;
            $extra = ['piggy' => ['values' => $values, 'collect' => $collect, 'coins' => $n]];
        }
        // EpicCatch: every wild collects all the fish values (tenths of the bet)
        if (($cfg['feature'] ?? '') === 'fish') {
            $values = [];
            $sum = 0;
            foreach ($grid as $r => $col) {
                foreach ($col as $row => $sym) {
                    $values[$r][$row] = in_array($sym, (array) $cfg['fish'], true) ? (int) $this->pick((array) $cfg['fish_values']) : 0;
                    $sum += $values[$r][$row];
                }
            }
            $tenths = $sum * $this->countIn($grid, (int) $cfg['wild']);
            $extra = ['fish' => ['values' => $values, 'collect' => $tenths]];
            $collect = $tenths / 10;
        }
        if (($cfg['evaluation'] ?? 'lines') === 'ways') {
            $pays = $this->waysPays($cfg, $grid);

            return ['pays' => $pays, 'win' => array_sum($pays) / (float) $cfg['ways_unit'] + $collect, 'pot' => $pot] + $extra;
        }
        $pays = [];
        foreach ((array) $cfg['lines'] as $line) {
            $pays[] = $this->linePay($cfg, $grid, $line);
        }

        return ['pays' => $pays, 'win' => array_sum($pays) / max(1, count((array) $cfg['lines'])) + $collect, 'pot' => $pot] + $extra;
    }

    /**
     * Cleo: a cash-pot coin from the strips stays one only `pot_keep` of the
     * time, else it lands as a plain coin (Amatic's server lets pots near a
     * collector far less often than its strips would).
     *
     * @param  array<string, mixed>  $cfg
     */
    private function thinPots(array $cfg, array $grid): array
    {
        $keep = (float) ($cfg['pot_keep'] ?? 1.0);
        if ($keep >= 1.0) {
            return $grid;
        }
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $sym) {
                if (isset(((array) $cfg['jackpots'])[$sym]) && ! $this->chance($keep)) {
                    $grid[$r][$row] = (int) $cfg['coin'];
                }
            }
        }

        return $grid;
    }

    /**
     * Ways: every paying symbol on adjacent reels from the left (substitutes
     * joining), pay × the product of its cells per reel; one slot per symbol.
     *
     * @param  array<string, mixed>  $cfg
     * @return list<float>
     */
    private function waysPays(array $cfg, array $grid): array
    {
        $subs = array_map('intval', (array) $cfg['substitutes']);
        $pays = (array) $cfg['pays'];
        $out = array_fill(0, (int) $cfg['symbol_count'], 0.0);
        // a group of symbols that pay together (EpicCatch's fish: any mix), under its last member's slot
        $group = array_map('intval', (array) ($cfg['ways_group'] ?? []));
        foreach ($pays as $sym => $row) {
            if (max((array) $row) <= 0 || in_array((int) $sym, $subs, true)
                || ($group !== [] && in_array((int) $sym, $group, true) && (int) $sym !== end($group))) {
                continue;
            }
            $members = $group !== [] && (int) $sym === end($group) ? $group : [(int) $sym];
            $ways = 1;
            $n = 0;
            foreach ($grid as $col) {
                $c = count(array_filter($col, fn ($s) => in_array($s, $members, true) || in_array($s, $subs, true)));
                if ($c === 0) {
                    break;
                }
                $ways *= $c;
                $n++;
            }
            $pay = (float) (((array) $row)[$n - 1] ?? 0);
            if ($n > 0 && $pay > 0) {
                $out[(int) $sym] = $pay * $ways;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $cfg  the longest left-to-right run's pay on a line (substitutes count for any symbol) */
    private function linePay(array $cfg, array $grid, array $line): float
    {
        $subs = array_map('intval', (array) $cfg['substitutes']);
        $pays = (array) $cfg['pays'];
        $syms = [];
        foreach ($line as $r => $row) {
            $syms[] = (int) $grid[$r][$row];
        }
        $best = 0.0;
        // a run of substitutes alone pays as the top symbol (as Amatic's server pays it)
        $candidates = array_unique(array_merge(array_filter($syms, fn ($s) => ! in_array($s, $subs, true)), [(int) ($cfg['top'] ?? 0)]));
        foreach ($candidates as $sym) {
            $n = 0;
            foreach ($syms as $s) {
                if ($s !== $sym && ! in_array($s, $subs, true)) {
                    break;
                }
                $n++;
            }
            $best = max($best, (float) (((array) ($pays[$sym] ?? []))[$n - 1] ?? 0));
        }

        return $best;
    }

    /** Merlin: the expanding symbol on `$d` reels (gem reels counting) pays its `$d` pay on every line. */
    private function expandingPay(array $cfg, array $grid, int $special, array $marked): float
    {
        $d = 0;
        foreach ($grid as $r => $col) {
            if ($marked[$r] || in_array($special, $col, true)) {
                $d++;
            }
        }
        $pay = (float) (((array) (((array) $cfg['pays'])[$special] ?? []))[$d - 1] ?? 0);

        return $d >= 2 && $pay > 0 ? $pay : 0.0;   // pay × lines × line bet = pay × total bet
    }

    private function freeSpinsFor(array $cfg, int $scatters): int
    {
        $grant = 0;
        foreach ((array) $cfg['free_spins'] as $count => $spins) {
            if ($scatters >= (int) $count) {
                $grant = (int) $spins;
            }
        }

        return $grant;
    }

    /** @param array{grid: list<list<int>>} $s */
    private function count(array $s, int $symbol): int
    {
        return $this->countIn($s['grid'], $symbol);
    }

    private function countIn(array $grid, int $symbol): int
    {
        $n = 0;
        foreach ($grid as $col) {
            $n += count(array_keys($col, $symbol, true));
        }

        return $n;
    }

    /**
     * PiggyPots: a base spin (with the collectors' respin), and the Hold & Win
     * it starts (6+ coins, or `$boost` holdwin / cashpot) or its bonus spins —
     * where 6+ coins start a Hold & Win too.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $boost  `holdwin`, `cashpot`, `natural` (a gated CASHPOT starts from the coins that landed)
     * @return array<string, mixed>
     */
    private function piggyRound(array $cfg, int $forceScatters, array $boost): array
    {
        $base = $this->spinGrid($cfg, (array) $cfg['strips'], $forceScatters);
        $respin = null;
        $hwBoost = ! empty($boost['holdwin']);
        $cashpot = ! empty($boost['cashpot']);
        if ($forceScatters === 0 && ! $hwBoost) {
            [$base, $respin] = $this->collectRespin($cfg, $base);
        }
        if ($hwBoost) {
            $plant = $cashpot && ! empty($boost['natural']) ? random_int(1, (int) $cfg['hw_trigger'] - 1) : (int) $cfg['hw_trigger'];
            $base = $this->plantCoins($cfg, $base, $plant);
        }
        $holdwin = null;
        if ($cashpot || (int) $base['piggy']['coins'] >= (int) $cfg['hw_trigger']) {
            [$base, $holdwin] = $this->piggyHoldWin($cfg, $base, $cashpot);
        }
        $scatters = $this->count($base, (int) $cfg['scatter']);
        $granted = $holdwin === null ? $this->freeSpinsFor($cfg, $scatters) : 0;
        $bonus = [];
        $bonusWin = 0.0;
        $left = $total = $granted;
        while ($left > 0 && count($bonus) < 300) {
            $s = $this->spinGrid($cfg, (array) $cfg['bonus_strips']);
            $hw = null;
            if ((int) $s['piggy']['coins'] >= (int) $cfg['hw_trigger']) {
                [$s, $hw] = $this->piggyHoldWin($cfg, $s, false);
            }
            $left--;
            $more = $this->freeSpinsFor($cfg, $this->count($s, (int) $cfg['scatter']));
            $left += $more;
            $total += $more;
            $spinWin = $s['win'] + ($hw['total'] ?? 0.0);
            $bonusWin += $spinWin;
            $bonus[] = $s + ['holdwin' => $hw, 'spin_win' => $spinWin, 'more' => $more, 'left' => $left, 'total' => $total];
        }

        return [
            'grid' => $base['grid'], 'pays' => $base['pays'], 'win' => $base['win'], 'pot' => 0.0, 'scatters' => $scatters,
            'bonus' => $bonus, 'total' => round($base['win'] + ($holdwin['total'] ?? 0.0) + $bonusWin, 4), 'special' => -1, 'granted' => $granted,
            'start_marked' => [], 'wheel' => [], 'mult' => 1, 'respin' => $respin, 'choice' => null, 'eval' => $base,
            'holdwin' => $holdwin, 'cashpot' => $cashpot,
        ];
    }

    /**
     * The Hold & Win a board starts: its coins (and collectors) move onto the
     * 5×5 board, so they no longer pay as a collect.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $s
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function piggyHoldWin(array $cfg, array $s, bool $cashpot): array
    {
        $s['win'] -= (float) $s['piggy']['collect'];
        $s['piggy']['collect'] = 0.0;
        $hw = $this->hold->play(['hw_values' => $cfg[$cashpot ? 'cashpot_hw_values' : 'hw_values'], 'hw_coin' => $cfg[$cashpot ? 'cashpot_hw_coin' : 'hw_coin'] ?? $cfg['hw_coin']] + $cfg, $this->hold->fromBase($s['piggy']['values']), $cashpot);

        return [$s, $hw];
    }

    /**
     * Coins planted on cells holding no coin / collector / bonus symbol until the board has `$n`.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function plantCoins(array $cfg, array $base, int $n): array
    {
        $grid = $base['grid'];
        $cells = [];
        foreach ($grid as $r => $col) {
            foreach ($col as $row => $sym) {
                if (! in_array($sym, [(int) $cfg['coin'], (int) $cfg['collector'], (int) $cfg['scatter']], true)) {
                    $cells[] = [$r, $row];
                }
            }
        }
        shuffle($cells);
        foreach (array_slice($cells, 0, max(0, $n - (int) $base['piggy']['coins'])) as [$r, $row]) {
            $grid[$r][$row] = (int) $cfg['coin'];
        }

        return ['grid' => $grid] + $this->evaluate($cfg, $grid);
    }

    /**
     * Every board a PiggyPots round shows, in order (respin start, base, bonus spins).
     *
     * @param  array<string, mixed>  $round
     * @return list<array<string, mixed>>
     */
    private function piggyBoards(array $round): array
    {
        return array_values(array_merge(array_filter([$round['respin'] ?? null, $round['eval'] ?? null]), (array) ($round['bonus'] ?? [])));
    }

    // ---- frames ----------------------------------------------------

    /**
     * PiggyPots' frames: base 9 (respin 30 → 33); Hold & Win 45 natural / 64
     * bought / 71 after a respin, 46 per respin, 48 last; bonus spins 5 / 66
     * bought, 6, 10 more spins, 14 last — a Hold & Win in them 52, 53 per
     * respin, 54 back to the spins (56 when it was the last spin). Base wins
     * pay at once, a bonus on its last frame.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $round
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function piggyFrames(array $cfg, array $round, int $betIndex, float $stake, int $before, int $balance, float $paid, bool $bought, array $state): array
    {
        $pig = (array) ($state['piggy'] ?? ['coins' => 0, 'collect' => 0]);
        $seen = (int) $pig['coins'];
        $lastCollect = (int) $pig['collect'];
        $zero = [0, 0, 0, 0, 0, 0];
        $noWins = array_fill(0, count($round['pays']), 0.0);
        // the tail of a board: its coins, the session's coin count and last collect
        $tail = function (array $s, array $hw = [], int $spinsWon = 0) use (&$seen, &$lastCollect): array {
            return ['pig' => $hw + [
                'values' => $s['piggy']['values'], 'collect' => (float) $s['piggy']['collect'], 'cFL' => $seen, 'juF' => $lastCollect, 'j_y' => $spinsWon,
            ]];
        };
        $count = function (array $s) use (&$seen, &$lastCollect, $stake): void {
            $seen += (int) $s['piggy']['coins'];
            if ((float) $s['piggy']['collect'] > 0) {
                $lastCollect = $this->cents((float) $s['piggy']['collect'] * $stake);
            }
        };
        // a Hold & Win: its start board, then one frame per respin
        $hwFrames = function (array $hw, array $s, int $startCode, int $stepCode, int $lastCode, array $header, bool $cashpot, ?array $final) use ($cfg, $betIndex, $stake, $tail, $noWins): array {
            [$bal, $win, $counters] = $header;
            $board = fn (array $b, array $l, int $left, int $spins) => ['ju2' => $left, 'juC' => $spins, 'juU' => $this->cents($this->hold->sum($b) * $stake), 'cFE' => $b, 'cFm' => $l, 'cF5' => $cashpot ? 1 : 0];
            $out = [];
            $last = count($hw['steps']) - 1;
            foreach ($hw['steps'] as $k => $step) {
                [$fb, $fw] = $k === $last && $final !== null ? $final : [$bal, $win + ($k === $last ? $this->cents($hw['total'] * $stake) : 0)];
                $out[] = $this->frame($cfg, $k === $last ? $lastCode : $stepCode, $fb, $fw, $betIndex, $noWins, $counters, $s['grid'],
                    $tail($s, $board($step['board'], $step['landed'], $k === $last ? 0 : $step['left'], $k + 1)));
            }

            return array_merge([$this->frame($cfg, $startCode, $bal, $win, $betIndex, $s['pays'], $counters, $s['grid'],
                $tail($s, $board($hw['start']['board'], $hw['start']['landed'], 3, 0)))], $out);
        };

        $base = (array) $round['eval'];
        $baseCents = $this->cents($round['win'] * $stake);
        $frames = [];
        if (($round['respin'] ?? null) !== null) {
            $count($round['respin']);
            $frames[] = $this->frame($cfg, 30, $before, 0, $betIndex, $noWins, $zero, $round['respin']['grid'], $tail($round['respin']));
        }
        $count($base);
        if ($round['holdwin'] !== null) {
            $code = $bought ? 64 : (($round['respin'] ?? null) !== null ? 71 : 45);

            return array_merge($frames, $hwFrames($round['holdwin'], $base, $code, 46, 48, [$before, $baseCents, $zero], (bool) $round['cashpot'], [$balance, $this->cents($paid)]));
        }
        if ($round['bonus'] === []) {
            $frames[] = $this->frame($cfg, ($round['respin'] ?? null) !== null ? 33 : 9, $balance, $baseCents, $betIndex, $round['pays'], $zero, $round['grid'], $tail($base));

            return $frames;
        }
        $frames[] = $this->frame($cfg, $bought ? 66 : 5, $before, $baseCents, $betIndex, $round['pays'],
            [$round['granted'], $round['granted'], 0, 1, 1, 0], $round['grid'], $tail($base));
        $running = $baseCents;
        $bonusTotal = 0;
        $last = count($round['bonus']) - 1;
        foreach ($round['bonus'] as $k => $s) {
            $count($s);
            $final = $k === $last;
            $lineCents = $this->cents($s['win'] * $stake);
            $spinCents = $this->cents($s['spin_win'] * $stake);
            $counters = [$s['total'], $s['left'], $spinCents, 1, 1, $bonusTotal + $spinCents];
            if ($s['holdwin'] !== null) {
                $frames = array_merge($frames, $hwFrames($s['holdwin'], $s, 52, 53, $final ? 56 : 54, [$before, $running + $lineCents, $counters], false,
                    $final ? [$balance, $this->cents($paid)] : null));
            } else {
                $frames[] = $this->frame($cfg, $final ? 14 : ($s['more'] > 0 ? 10 : 6), $final ? $balance : $before, $final ? $this->cents($paid) : $running + $spinCents,
                    $betIndex, $s['pays'], $counters, $s['grid'], $tail($s, [], (int) $s['more']));
            }
            $running += $spinCents;
            $bonusTotal += $spinCents;
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $round
     * @return list<string>
     */
    private function frames(GameContext $ctx, array $round, int $betIndex, float $stake, int $before, float $paid, bool $bought = false, array $state = []): array
    {
        $cfg = (array) $this->table($ctx)['lines'];
        $balance = $this->cents($ctx->balance());
        $baseCents = $this->cents($round['win'] * $stake);
        $mult = (int) $round['mult'];
        $cleo = (array) ($this->remember($cfg, $round, $stake, $state)['cleo'] ?? []);
        $board = fn (array $s) => ['coins' => $s['coins'] ?? null, 'stake' => $stake, 'cleo' => $cleo, 'fish' => $s['fish'] ?? null,
            'wilds_seen' => $s['wilds_seen'] ?? 0, 'spin_mult' => $s['spin_mult'] ?? 1, 'ftotal' => $s['total'] ?? 0];
        $frames = [];
        // Cleo's respin: the board with the two collectors first, then the respun one
        if (($round['respin'] ?? null) !== null) {
            $first = $round['respin'];
            $frames[] = $this->frame($cfg, 30, $before, 0, $betIndex, array_fill(0, count($round['pays']), 0.0), [0, 0, 0, 0, 0, 0], $first['grid'],
                ['pot' => 0.0, 'coins' => null, 'stake' => $stake, 'cleo' => $cleo]);
        }
        if ($round['bonus'] === []) {
            $code = $round['choice'] !== null ? 16 : (($round['respin'] ?? null) !== null ? 33 : 9);
            $frames[] = $this->frame($cfg, $code, $balance, $baseCents, $betIndex, $round['pays'], [0, 0, 0, 0, 0, 0], $round['grid'],
                ['pot' => $round['pot']] + $board((array) ($round['eval'] ?? [])));

            return $frames;
        }
        // the bonus pays on its last frame; until then the balance is the post-stake one
        $running = $baseCents;
        $bonusTotal = 0;
        $common = ['special' => $round['special'], 'granted' => $round['granted']];
        $frames[] = $this->frame($cfg, $bought ? 66 : 5, $before, $baseCents, $betIndex, $round['pays'],
            [$round['granted'], $round['granted'], 0, $mult, $mult, 0], $round['grid'],
            $common + ['marked' => $round['start_marked'], 'pot' => $round['pot'], 'wheel' => $round['wheel']] + $board((array) ($round['eval'] ?? [])));
        $last = count($round['bonus']) - 1;
        foreach ($round['bonus'] as $k => $s) {
            $spinCents = $this->cents($s['spin_win'] * $stake);
            $running += $spinCents;
            $bonusTotal += $spinCents;
            $final = $k === $last;
            $code = $final ? 14 : ($s['more'] > 0 ? 10 : 6);
            $frames[] = $this->frame($cfg, $code, $final ? $balance : $before, $final ? $this->cents($paid) : $running, $betIndex, $s['pays'],
                [$s['total'], $s['left'], $spinCents, $mult, $mult, $bonusTotal], $s['grid'],
                $common + ['expanding' => $this->cents($s['expanding'] * $stake), 'marked' => $s['marked'], 'pot' => $s['pot']] + $board($s));
        }

        return $frames;
    }

    /**
     * One spin frame: WildCat's extended header (reel positions, bet,
     * lines, bonus counters), the wins (lines: per line + a scatter slot;
     * ways: per symbol), the cards, then the game's tail.
     *
     * @param  list<float>  $pays
     * @param  array{0:int,1:int,2:int,3:int,4:int,5:int}  $bonus  total, left, spin win (cents), multiplier, multiplier, bonus total (cents)
     * @param  array<string, mixed>  $x  tail data: special, expanding (cents), marked, granted, pot (× stake), wheel
     */
    private function frame(array $cfg, int $code, int $balance, int $win, int $betIndex, array $pays, array $bonus, array $grid, array $x): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $reels = count($grid);
        $wins = array_map(fn ($p) => $h(round($p)), $pays);
        if (($cfg['evaluation'] ?? 'lines') !== 'ways') {
            $wins[] = '10';
        }
        $out = '-11'.sprintf('%02x', $code).'0'.$h(random_int(0x10000000, 0x7fffffff)).$h($balance).$h($win)
            .sprintf('%02x', $reels).str_repeat('10', max(5, $reels))
            .sprintf('%02x', $betIndex).sprintf('%02x', (int) ($cfg['wire_lines'] ?? count((array) $cfg['lines'])))
            .implode('', array_map($h, $bonus))
            .sprintf('%02x', $reels).str_repeat('10', max(5, $reels))
            .sprintf('%02x', count($wins)).implode('', $wins).str_repeat('00', 8);

        return $out.match ((string) ($cfg['tail'] ?? '')) {
            'merlin' => $this->merlinTail($grid, $x),
            'rampage' => $this->rampageTail($grid, $x),
            'cleo' => $this->cleoTail($grid, $x),
            'epic' => $this->epicTail($grid, $x),
            'piggy' => $this->piggyTail($grid, $x),
            default => '',
        }.'#101010';
    }

    /** a list of single hex digits: count, then one character each */
    private function digits(array $l): string
    {
        return $this->fmt->hexFmt(count($l)).implode('', array_map(fn ($v) => dechex((int) $v), $l));
    }

    /** Merlin (`fWK`): version 5, two unused flags, expanding symbol, its win, gem reels, the board, spins won, a flag. */
    private function merlinTail(array $grid, array $x): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $marked = ($x['marked'] ?? []) ?: array_fill(0, count($grid), false);
        $special = (int) ($x['special'] ?? -1);

        return $h(5).'ff'.($special >= 0 ? dechex($special) : 'f').$h((int) ($x['expanding'] ?? 0))
            .$this->digits(array_map(fn ($m) => $m ? 1 : 0, $marked))
            .$h(count($grid)).implode('', array_map(fn ($c) => $this->digits($c), $grid))
            .$h((int) ($x['granted'] ?? 0)).'0';
    }

    /**
     * Cleo (`UM0`): version 2, a count, two unused flags, the board (letters),
     * the last collect (cents), the last bonus choice, a flag, its spins, the
     * ADD value (cents), the MULTIPLY value, this collect (cents), the coin values (cents).
     */
    private function cleoTail(array $grid, array $x): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $stake = (float) ($x['stake'] ?? 0);
        $coins = (array) ($x['coins'] ?? []);
        $cleo = (array) ($x['cleo'] ?? []);
        $values = $coins['values'] ?? array_map(fn ($c) => array_fill(0, count($c), 0.0), $grid);

        return $h(2).$h(0).'ff'
            .$h(count($grid)).implode('', array_map(fn ($c) => $h(count($c)).implode('', array_map(fn ($s) => $letters[(int) $s], $c)), $grid))
            .$h((int) ($cleo['collect'] ?? 0)).$h((int) ($cleo['choice'] ?? 0)).'0'.$h((int) ($cleo['spins'] ?? 0))
            .$h($this->cents((float) ($coins['add'] ?? 0) * $stake)).$h((int) ($coins['multiply'] ?? 1))
            .$h($this->cents((float) ($coins['collect'] ?? 0) * $stake))
            .$h(count($values)).implode('', array_map(fn ($c) => $h(count($c)).implode('', array_map(fn ($v) => $h($this->cents((float) $v * $stake)), $c)), $values));
    }

    /**
     * EpicCatch (`I1F`): version 4, a count, two unused flags, the bonus's
     * spins, wilds collected, the current multiplier, this spin's fish collect
     * and the fish values (tenths of the bet), the board as hex digits between.
     */
    private function epicTail(array $grid, array $x): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $fish = (array) ($x['fish'] ?? []);
        $values = $fish['values'] ?? array_map(fn ($c) => array_fill(0, count($c), 0), $grid);

        return $h(4).$h(0).'ff'
            .$h((int) (($x['ftotal'] ?? 0) ?: ($x['granted'] ?? 0))).$h((int) ($x['wilds_seen'] ?? 0)).$h((int) ($x['spin_mult'] ?? 1))
            .$h((int) ($fish['collect'] ?? 0) * max(1, (int) ($x['spin_mult'] ?? 1)))
            .$h(count($grid)).implode('', array_map(fn ($c) => $this->digits($c), $grid))
            .$h(count($values)).implode('', array_map(fn ($c) => $h(count($c)).implode('', array_map(fn ($v) => $h((int) $v), $c)), $values));
    }

    /**
     * Rampage (`fQ3`): max win factor, a flag, spins won, the golden-shield pot
     * (× stake), the board, then the bonus wheel's spins / multipliers (trigger only).
     */
    private function rampageTail(array $grid, array $x): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $list = fn (array $l) => $h(count($l)).implode('', array_map($h, $l));
        $wheel = (array) ($x['wheel'] ?? []);

        return $h(50000).'f'.$h((int) ($x['granted'] ?? 0)).$h((int) round((float) ($x['pot'] ?? 0)))
            .$h(count($grid)).implode('', array_map(fn ($c) => $this->digits($c), $grid))
            .$list(array_column($wheel, 0)).$list(array_column($wheel, 1));
    }

    /**
     * PiggyPots (`c87`): version 3, a count, two unused flags, the spins just
     * won, this collect (× bet), the board, the coin values (× bet, a
     * collector -1), Hold & Win respins left / played / total (cents), its
     * board after and before the collectors took the coins (× bet, collector
     * -1), the coins seen, the CASHPOT flag and the last collect (cents).
     */
    private function piggyTail(array $grid, array $x): string
    {
        $h = fn (int|float $n) => $this->fmt->hexFmt($n);
        $p = (array) ($x['pig'] ?? []);
        $empty = array_fill(0, AmaticPiggyHoldWin::SIZE, array_fill(0, AmaticPiggyHoldWin::SIZE, 0.0));
        $values = $p['values'] ?? array_map(fn ($c) => array_fill(0, count($c), 0), $grid);
        $unsigned = fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => $h($v < 0 ? 0xffffffff : (int) round($v)), $l));
        $signed = fn (array $l) => $h(count($l)).implode('', array_map(fn ($v) => ($v < 0 ? '-' : '').$h((int) round(abs($v))), $l));
        $lists = fn (array $b, callable $f) => $h(count($b)).implode('', array_map($f, $b));

        return $h(3).$h(0).'ff'.$h((int) ($p['j_y'] ?? 0)).$h((int) round((float) ($p['collect'] ?? 0)))
            .$h(count($grid)).implode('', array_map(fn ($c) => $this->digits($c), $grid))
            .$lists($values, $unsigned)
            .$h((int) ($p['ju2'] ?? 0)).$h((int) ($p['juC'] ?? 0)).$h((int) ($p['juU'] ?? 0))
            .$lists($p['cFE'] ?? $empty, $signed).$lists($p['cFm'] ?? $empty, $signed)
            .$h((int) ($p['cFL'] ?? 0)).$h((int) ($p['cF5'] ?? 0)).$h((int) ($p['juF'] ?? 0));
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
        return (array) (((array) $ctx->stateGet('features', []))['wcglines'] ?? []);
    }

    /** @param array<string, mixed> $state */
    private function save(GameContext $ctx, array $state): void
    {
        $ctx->statePut(['features' => ['wcglines' => $state]]);
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
