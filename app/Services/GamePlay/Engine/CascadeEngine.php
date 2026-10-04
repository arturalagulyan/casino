<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * The platform's own scatter-pays + cascade ("tumble") engine — the math behind
 * the RoyalSpin cascade games on the standard JSON protocol. Unlike
 * {@see TumbleEngine} (a faithful port of Pragmatic's legacy step-per-request
 * state machine), one call here resolves a *whole* round server-side and hands
 * the client every intermediate board to animate:
 *
 *   draw the grid from the reel strips → pay every symbol landing at least
 *   `tiers[0]` times anywhere → remove the winners, let the rest fall, refill
 *   from the top → repeat until nothing pays. Scatters and multiplier bombs
 *   never pay as symbols and are never removed. During free spins every bomb
 *   left on the final grid carries a value; their sum multiplies the round's
 *   cascade win.
 *
 * Gated like {@see SlotEngine}: {@see SpinDecider} picks none / win / bonus,
 * then whole rounds are simulated until one matches that outcome and fits the
 * bank + win cap — same RTP feedback loop, same bank floor, no per-game code.
 */
class CascadeEngine
{
    private const int MAX_TRIES = 600;

    /** Hard stop for a runaway cascade (strips that keep refilling winners). */
    private const int MAX_STEPS = 40;

    public function __construct(private readonly SpinDecider $decider) {}

    public function spin(GameContext $context, float $stake, float $betline, bool $free): SpinResult
    {
        $cfg = $context->config();
        $cc = $cfg->cascadeConfig();
        $decision = $this->decider->decide($context, $free ? 'bonus' : 'spin', $cc['lines'], $stake);

        $ceiling = min(
            $decision->budget * $cfg->winDistribution()['budget_frac'],
            $stake * max(0.25, $decision->maxWinMultiplier),
        );
        if ($decision->winScale < 1.0) {
            $ceiling = min($ceiling, $stake * max(1.0, 5 * $decision->winScale));
        }

        $need = $free ? $cc['retrigger'] : $cc['trigger'];
        $wantBonus = $decision->type === 'bonus';
        $wantWin = $decision->isWin();

        $best = null;
        $bestScore = INF;

        for ($i = 0; $i < self::MAX_TRIES; $i++) {
            $round = $this->play($cfg, $cc, $betline, $free, $wantBonus ? $need : 0);
            $trigger = $round['scatters'] >= $need;
            $win = $round['win'];

            if ($wantBonus) {
                if ($trigger && $win <= $ceiling) {
                    $best = $round;
                    break;
                }
                $score = ($trigger ? 0 : 1e9) + max(0, $win - $ceiling);
            } elseif (! $wantWin) {
                if ($win <= 0 && ! $trigger) {
                    $best = $round;
                    break;
                }
                $score = $win + ($trigger ? 1e9 : 0);
            } else {
                if (! $trigger && $win > 0 && $win <= $ceiling) {
                    $best = $round;
                    break;
                }
                $score = ($trigger ? 1e9 : 0) + ($win <= 0 ? 1e6 : max(0, $win - $ceiling));
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $round;
            }
        }

        /** @var array{steps: list<array<string,mixed>>, grid: array<int,list<int>>, win: float, cascade_win: float, scatters: int, scatter_cells: list<array{0:int,1:int}>, scatter_win: float, multiplier: int} $best */
        $lines = [];
        foreach ($best['steps'] as $s => $step) {
            foreach ($step['wins'] as $w) {
                $lines[] = ['line' => -1, 'step' => $s] + $w;
            }
        }
        if ($best['scatter_win'] > 0) {
            $lines[] = ['line' => -1, 'symbol' => $cfg->scatterSymbol(), 'count' => $best['scatters'], 'amount' => $best['scatter_win'], 'cells' => $best['scatter_cells']];
        }

        return new SpinResult(
            bet: $free ? 0.0 : $stake,
            win: round(min($best['win'], $ceiling), 4),
            reels: $best['grid'],
            lines: $lines,
            state: $free ? 'freespin' : 'bet',
            extra: [
                'cascade' => $best['steps'],
                'cascade_win' => $best['cascade_win'],
                'scatters' => $best['scatters'],
                'scatter_cells' => $best['scatter_cells'],
                'scatter_win' => $best['scatter_win'],
                'multiplier' => $best['multiplier'],
                'decision' => $decision->type,
            ],
        );
    }

    /**
     * Play one complete round on the real strips.
     *
     * @param  int  $forceScatters  land at least this many scatters (a gated bonus round)
     * @return array{steps: list<array<string,mixed>>, grid: array<int,list<int>>, win: float, cascade_win: float, scatters: int, scatter_cells: list<array{0:int,1:int}>, scatter_win: float, multiplier: int}
     */
    public function play(GameConfig $cfg, array $cc, float $betline, bool $free, int $forceScatters = 0): array
    {
        $strips = $cfg->reelStrips($free);
        $rows = $cfg->rowCount();
        $scatter = $cfg->scatterSymbol();
        $bomb = $cc['multiplier_symbol'];

        $grid = [];
        foreach ($strips as $reel => $strip) {
            $grid[$reel] = $this->take($strip, $rows);
        }

        if ($forceScatters > 0 && $scatter !== null) {
            $grid = $this->plantScatters($grid, $scatter, $forceScatters, $rows);
        }

        // Bomb values ride along with their cell as it falls.
        $values = $this->assignBombs($grid, $bomb, $free, $cc['multiplier_values']);

        $steps = [];
        $cascadeWin = 0.0;

        for ($s = 0; $s < self::MAX_STEPS; $s++) {
            $wins = $this->evaluate($cfg, $cc, $grid, $betline);
            $stepWin = round(array_sum(array_column($wins, 'amount')), 4);

            $steps[] = [
                'grid' => $grid,
                'bombs' => $this->bombList($grid, $values, $bomb),
                'wins' => $wins,
                'win' => $stepWin,
            ];

            if ($wins === []) {
                break;
            }

            $cascadeWin += $stepWin;
            [$grid, $values] = $this->tumble($grid, $values, $wins, $strips, $rows, $bomb, $free, $cc['multiplier_values']);
        }

        $multiplier = 0;
        if ($free && $cascadeWin > 0 && $bomb !== null) {
            foreach ($this->bombList($grid, $values, $bomb) as $b) {
                $multiplier += $b['value'];
            }
        }

        $scatterCells = [];
        if ($scatter !== null) {
            foreach ($grid as $reel => $col) {
                foreach ($col as $row => $sym) {
                    if ($sym === $scatter) {
                        $scatterCells[] = [$reel, $row];
                    }
                }
            }
        }
        $scatters = count($scatterCells);
        $scatterWin = 0.0;
        if ($scatters >= ($free ? $cc['retrigger'] : $cc['trigger'])) {
            $coef = 0.0;
            foreach ($cc['scatter_pays'] as $count => $c) {
                if ($scatters >= $count) {
                    $coef = $c;
                }
            }
            $scatterWin = round($coef * $betline, 4);
        }

        $paid = $multiplier > 0 ? $cascadeWin * $multiplier : $cascadeWin;

        return [
            'steps' => $steps,
            'grid' => $grid,
            'win' => round($paid + $scatterWin, 4),
            'cascade_win' => round($cascadeWin, 4),
            'scatters' => $scatters,
            'scatter_cells' => $scatterCells,
            'scatter_win' => $scatterWin,
            'multiplier' => $multiplier,
        ];
    }

    /**
     * Scatter-pays: every paying symbol counted anywhere on the grid.
     *
     * @param  array<int,list<int>>  $grid
     * @return list<array{symbol:int, count:int, amount:float, cells:list<array{0:int,1:int}>}>
     */
    public function evaluate(GameConfig $cfg, array $cc, array $grid, float $betline): array
    {
        $paytable = $cfg->paytable();
        $skip = array_filter([$cfg->scatterSymbol(), $cc['multiplier_symbol']], fn ($s) => $s !== null);
        $tiers = $cc['tiers'];

        $cells = [];
        foreach ($grid as $reel => $col) {
            foreach ($col as $row => $sym) {
                if (! in_array($sym, $skip, true)) {
                    $cells[$sym][] = [$reel, $row];
                }
            }
        }

        $wins = [];
        foreach ($cells as $sym => $at) {
            $count = count($at);
            $tier = -1;
            foreach ($tiers as $i => $min) {
                if ($count >= $min) {
                    $tier = $i;
                }
            }
            $coef = $tier >= 0 ? (float) ($paytable[$sym][$tier] ?? 0) : 0.0;
            if ($coef > 0) {
                $wins[] = ['symbol' => (int) $sym, 'count' => $count, 'amount' => round($coef * $betline, 4), 'cells' => $at];
            }
        }

        return $wins;
    }

    // ---- board mechanics -----------------------------------------------

    /** @return list<int> $n consecutive symbols from a random strip position */
    private function take(array $strip, int $n): array
    {
        $len = max(1, count($strip));
        $at = random_int(0, $len - 1);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = (int) $strip[($at + $i) % $len];
        }

        return $out;
    }

    private function plantScatters(array $grid, int $scatter, int $need, int $rows): array
    {
        $have = 0;
        $free = [];
        foreach ($grid as $reel => $col) {
            $have += count(array_keys($col, $scatter, true));
            if (! in_array($scatter, $col, true)) {
                $free[] = $reel;
            }
        }
        shuffle($free);
        while ($have < $need && $free !== []) {
            $reel = array_pop($free);
            $grid[$reel][random_int(0, $rows - 1)] = $scatter;
            $have++;
        }

        return $grid;
    }

    /** @return array<int,array<int,int>> reel => row => bomb value */
    private function assignBombs(array $grid, ?int $bomb, bool $free, array $pool): array
    {
        $values = [];
        if (! $free || $bomb === null) {
            return $values;
        }
        foreach ($grid as $reel => $col) {
            foreach ($col as $row => $sym) {
                if ($sym === $bomb) {
                    $values[$reel][$row] = $pool[array_rand($pool)];
                }
            }
        }

        return $values;
    }

    /** @return list<array{reel:int,row:int,value:int}> */
    private function bombList(array $grid, array $values, ?int $bomb): array
    {
        $out = [];
        if ($bomb === null) {
            return $out;
        }
        foreach ($grid as $reel => $col) {
            foreach ($col as $row => $sym) {
                if ($sym === $bomb && isset($values[$reel][$row])) {
                    $out[] = ['reel' => $reel, 'row' => $row, 'value' => $values[$reel][$row]];
                }
            }
        }

        return $out;
    }

    /**
     * Remove the winning cells, drop survivors to the bottom, refill each reel
     * from the top with fresh strip symbols.
     *
     * @return array{0: array<int,list<int>>, 1: array<int,array<int,int>>}
     */
    private function tumble(array $grid, array $values, array $wins, array $strips, int $rows, ?int $bomb, bool $free, array $pool): array
    {
        $gone = [];
        foreach ($wins as $w) {
            foreach ($w['cells'] as [$reel, $row]) {
                $gone[$reel][$row] = true;
            }
        }

        $newGrid = [];
        $newValues = [];
        foreach ($grid as $reel => $col) {
            $keep = [];
            $keepVal = [];
            foreach ($col as $row => $sym) {
                if (! isset($gone[$reel][$row])) {
                    $keep[] = $sym;
                    $keepVal[] = $values[$reel][$row] ?? null;
                }
            }
            $missing = $rows - count($keep);
            $fresh = $missing > 0 ? $this->take($strips[$reel], $missing) : [];
            $freshVal = array_map(
                fn ($s) => ($free && $bomb !== null && $s === $bomb) ? $pool[array_rand($pool)] : null,
                $fresh,
            );

            $newGrid[$reel] = array_merge($fresh, $keep);
            foreach (array_merge($freshVal, $keepVal) as $row => $v) {
                if ($v !== null) {
                    $newValues[$reel][$row] = $v;
                }
            }
        }

        return [$newGrid, $newValues];
    }
}
