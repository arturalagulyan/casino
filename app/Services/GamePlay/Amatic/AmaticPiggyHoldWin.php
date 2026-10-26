<?php

namespace App\Services\GamePlay\Amatic;

/**
 * PiggyPots' Hold & Win: a 5×5 board (the base reels are its bottom three
 * rows), every empty position spinning on its own. Coins stick; a COLLECT
 * symbol takes every value on the board into its own cell (freeing the
 * others); anything landing resets the spins to 3. The bonus pays the sum of
 * the values left on the board. The CASHPOT version lands at least one cash
 * pot coin. Values are total-bet multiples.
 */
class AmaticPiggyHoldWin
{
    public const int SIZE = 5;

    /** Collectors stop landing after this many respins, so a bonus always ends (the board fills or the respins run out). */
    private const int COLLECTOR_SPINS = 60;

    /**
     * @param  array<string, mixed>  $cfg  `hw_coin` / `hw_collector` landing chance per empty position, `hw_values`, `cashpot_values` (value => weight)
     * @param  list<list<float>>  $start  the starting board (positions holding a coin value; -1 = a collector)
     * @return array{steps: list<array{landed: list<list<float>>, board: list<list<float>>, left: int}>, start: array{landed: list<list<float>>, board: list<list<float>>}, total: float}
     */
    public function play(array $cfg, array $start, bool $cashpot = false): array
    {
        $landed = $start;
        $board = $this->collect($start, array_fill(0, self::SIZE, array_fill(0, self::SIZE, 0.0)));
        $begin = ['landed' => $landed, 'board' => $board];
        $values = (array) $cfg['hw_values'];
        $pots = (array) $cfg['cashpot_values'];
        $potAt = $cashpot ? random_int(0, 2) : -1;   // the guaranteed cash pot lands on one of the first spins
        $left = 3;
        $steps = [];
        while ($left > 0 && $this->empties($board) !== [] && count($steps) < 200) {
            $landed = $board;
            $hit = false;
            foreach ($this->empties($board) as [$r, $row]) {
                if (count($steps) < self::COLLECTOR_SPINS && $this->chance((float) $cfg['hw_collector'])) {
                    $landed[$r][$row] = -1.0;
                    $hit = true;
                } elseif ($this->chance((float) $cfg['hw_coin'])) {
                    $landed[$r][$row] = (float) $this->pick($values);
                    $hit = true;
                }
            }
            if (count($steps) === $potAt && ($open = $this->empties($landed)) !== []) {
                [$r, $row] = $open[array_rand($open)];
                $landed[$r][$row] = (float) $this->pick($pots);
                $hit = true;
            }
            $board = $this->collect($landed, $board);
            $left = $hit ? 3 : $left - 1;
            $steps[] = ['landed' => $landed, 'board' => $board, 'left' => $left];
        }

        return ['steps' => $steps, 'start' => $begin, 'total' => $this->sum($board)];
    }

    /** The trigger board: the base reels' cells on the bottom three rows of the 5×5 board. */
    public function fromBase(array $baseValues): array
    {
        $rows = count($baseValues[0] ?? []);
        $board = array_fill(0, self::SIZE, array_fill(0, self::SIZE, 0.0));
        foreach ($baseValues as $r => $col) {
            foreach ($col as $row => $v) {
                $board[$r][self::SIZE - $rows + $row] = (float) $v;
            }
        }

        return $board;
    }

    /**
     * Each newly landed collector (-1 where `$before` was empty) takes every
     * value on the board — the last one ends up holding them all; one with
     * nothing to take stays a bare collector (-1).
     */
    public function collect(array $board, array $before): array
    {
        foreach ($board as $r => $col) {
            foreach ($col as $row => $v) {
                if ($v < 0 && (float) ($before[$r][$row] ?? 0) === 0.0) {
                    $sum = 0.0;
                    foreach ($board as $r2 => $col2) {
                        foreach ($col2 as $row2 => $v2) {
                            if ($v2 > 0) {
                                $sum += $v2;
                                $board[$r2][$row2] = 0.0;
                            }
                        }
                    }
                    $board[$r][$row] = $sum > 0 ? $sum : -1.0;
                }
            }
        }

        return $board;
    }

    public function sum(array $board): float
    {
        $sum = 0.0;
        foreach ($board as $col) {
            foreach ($col as $v) {
                $sum += max(0.0, (float) $v);
            }
        }

        return $sum;
    }

    /** @return list<array{0: int, 1: int}> */
    private function empties(array $board): array
    {
        $out = [];
        foreach ($board as $r => $col) {
            foreach ($col as $row => $v) {
                if ((float) $v === 0.0) {
                    $out[] = [$r, $row];
                }
            }
        }

        return $out;
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

    private function chance(float $p): bool
    {
        return random_int(0, 999999) < $p * 1000000;
    }
}
