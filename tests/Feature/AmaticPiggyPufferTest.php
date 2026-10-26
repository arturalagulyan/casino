<?php

namespace Tests\Feature;

use App\Services\GamePlay\Amatic\AmaticPiggyHoldWin;
use App\Services\GamePlay\Protocol\AmaticPufferHandler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WildCat PiggyPots (5×5 Hold & Win with collectors / cash pots) and
 * PufferIsland (wild meters, wilds scattered over a 5×5 bonus board).
 */
class AmaticPiggyPufferTest extends TestCase
{
    public function test_a_collector_takes_every_value_into_its_own_cell(): void
    {
        $hold = new AmaticPiggyHoldWin;
        $empty = array_fill(0, 5, array_fill(0, 5, 0.0));
        $board = $empty;
        $board[0][2] = 3.0;
        $board[1][4] = 5.0;
        $board[4][3] = -1.0;                         // a collector lands
        $after = $hold->collect($board, $empty);
        $this->assertSame(8.0, $after[4][3]);
        $this->assertSame(0.0, $after[0][2]);
        $this->assertSame(8.0, $hold->sum($after));
        // a collector with nothing to take stays a bare collector, and an old one collects nothing new
        $bare = $empty;
        $bare[2][2] = -1.0;
        $this->assertSame(-1.0, $hold->collect($bare, $empty)[2][2]);
        $later = $bare;
        $later[0][0] = 4.0;
        $this->assertSame(4.0, $hold->collect($later, $bare)[0][0]);
        // the base reels are the bottom three rows of the board
        $this->assertSame(7.0, $hold->fromBase([[0, 7, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0]])[0][3]);
    }

    public function test_the_hold_and_win_runs_out_of_respins_and_a_cashpot_one_lands_a_pot(): void
    {
        $hold = new AmaticPiggyHoldWin;
        $cfg = ['hw_coin' => 0.05, 'hw_collector' => 0.01, 'hw_values' => [1 => 10, 2 => 5], 'cashpot_values' => [50 => 3, 100 => 1]];
        $start = $hold->fromBase([[1, 0, 0], [0, 2, 0], [0, 0, 1], [2, 0, 0], [0, 1, 0]]);
        for ($i = 0; $i < 100; $i++) {
            $hw = $hold->play($cfg, $start, true);
            $last = end($hw['steps']);
            $this->assertTrue($last['left'] === 0 || ! in_array(0.0, array_merge(...$last['board']), true));
            $this->assertEqualsWithDelta($hold->sum($last['board']), $hw['total'], 1e-9);
            $landed = array_merge(...array_map(fn ($s) => array_merge(...$s['landed']), $hw['steps']));
            $this->assertNotEmpty(array_intersect([50.0, 100.0], $landed), 'a CASHPOT bonus lands a pot');
        }
    }

    public function test_puffer_wild_multipliers_multiply_on_a_line_up_to_five(): void
    {
        $handler = app(AmaticPufferHandler::class);
        $pays = new ReflectionMethod($handler, 'linePays');
        $cfg = ['substitutes' => [13, 14, 15], 'top' => 1, 'multiplier_max' => 5, 'pays' => [5 => [0, 0, 5, 10, 20], 1 => [0, 0, 25, 75, 150]]];
        $grid = [[5, 5, 5, 5, 5], [5, 5, 5, 5, 5], [5, 5, 8, 8, 8], [8, 8, 8, 8, 8], [8, 8, 8, 8, 8]];
        $line = [[0, 0, 0, 0, 0]];
        $mults = array_fill(0, 5, array_fill(0, 5, 0));
        $this->assertSame([5.0], $pays->invoke($handler, $cfg, $grid, $line, $mults));
        $mults[1][0] = 2;                            // a ×2 wild in the run
        $this->assertSame([10.0], $pays->invoke($handler, $cfg, $grid, $line, $mults));
        $mults[3][0] = 3;                            // ×2 · ×3 extends the run to 4, capped at ×5
        $this->assertSame([50.0], $pays->invoke($handler, $cfg, $grid, $line, $mults));
    }

    public function test_puffer_meters_fill_reset_and_hand_all_their_wilds_to_the_bonus(): void
    {
        $handler = app(AmaticPufferHandler::class);
        $strip = array_merge(array_fill(0, 30, 5), array_fill(0, 30, 6), array_fill(0, 30, 7), [13, 13, 16]);
        $cfg = [
            'rows' => 3, 'bonus_rows' => 5, 'scatter' => 16, 'trigger' => 3, 'substitutes' => [13, 14, 15], 'top' => 1, 'wilds' => [13, 14, 15],
            'meter_min' => [5, 20, 50], 'meter_max' => [30, 100, 125], 'bonus_spins' => [1, 10, 10], 'multiplier_max' => 5, 'bet_factor' => 20,
            'cashpots' => [12 => 50], 'cashpot_chance' => 0.0, 'cashpot_weights' => [12 => 1], 'buy_wilds' => [17, 83, 122],
            'strips' => array_fill(0, 5, $strip), 'bonus_strips' => array_fill(0, 5, array_merge(array_fill(0, 20, 5), array_fill(0, 20, 8))),
            'lines' => [[1, 1, 1, 1, 1]], 'bonus_lines' => [[2, 2, 2, 2, 2]], 'pays' => [5 => [0, 0, 5, 10, 20], 8 => [0, 0, 3, 5, 10], 1 => [0, 0, 25, 75, 150]],
        ];
        for ($i = 0; $i < 200; $i++) {
            $meters = [random_int(5, 29), 20, 50];
            $r = $handler->round($cfg, $meters);
            if ($r['variant'] < 0) {
                $landed = 0;
                foreach ($r['grid'] as $col) {
                    $landed += count(array_keys($col, 13, true));
                }
                $this->assertSame(min(30, $meters[0] + $landed), $r['meters_after'][0]);

                continue;
            }
            $this->assertSame(0, $r['variant']);    // only the super meter can fill here
            $this->assertSame(5, $r['meters_after'][0]);
        }
        foreach ([0, 1, 2] as $v) {
            $r = $handler->round($cfg, [5, 20, 50], $v, true);
            $this->assertSame($v, $r['variant']);
            $this->assertCount($cfg['bonus_spins'][$v], $r['spins']);
            $dropped = array_sum(array_map(fn ($s) => array_sum(array_map('array_sum', $s['mults'])), $r['spins']));
            $this->assertSame($cfg['buy_wilds'][$v], $dropped);
            $this->assertSame(0, end($r['spins'])['wilds_left']);
        }
    }
}
