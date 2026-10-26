<?php

namespace Tests\Feature;

use App\Services\GamePlay\Protocol\AmaticDinopupsHandler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WildCat Dinopups: ways from the left with wilds, coins and multipliers per
 * step, and the tumble (jumbo blocks fall as one piece).
 */
class AmaticDinopupsTest extends TestCase
{
    private const array CFG = [
        'wild' => 4, 'coin' => 0, 'multiplier' => 1, 'ways_unit' => 20,
        'pays' => [10 => [0, 0, 2, 3, 4, 5], 11 => [0, 0, 2, 3, 4, 5]],
    ];

    private function board(array $grid): array
    {
        $zero = array_fill(0, 6, array_fill(0, 6, 0));

        return ['grid' => $grid, 'blocks' => [], 'coins' => $zero, 'mults' => $zero];
    }

    public function test_ways_count_cells_per_reel_from_the_left_with_wilds(): void
    {
        $handler = app(AmaticDinopupsHandler::class);
        $ways = new ReflectionMethod($handler, 'ways');
        $grid = array_fill(0, 6, array_fill(0, 6, 9));
        $grid[0][0] = $grid[0][1] = 10;                         // 2 on reel 1
        $grid[1][3] = 4;                                        // a wild on reel 2
        $grid[2][5] = 10;                                       // 1 on reel 3
        [$total, $cells] = $ways->invoke($handler, self::CFG, $grid);
        $this->assertSame(2.0 * 2 * 1 * 1, $total);              // pay 2 × (2 · 1 · 1) ways; 11 has no cell on reel 1
        $this->assertTrue($cells[1][3]);
        $this->assertArrayNotHasKey(3, $cells);
    }

    public function test_a_step_pays_ways_and_coins_times_the_multipliers_then_clears_them(): void
    {
        $handler = app(AmaticDinopupsHandler::class);
        $tumble = new ReflectionMethod($handler, 'tumble');
        $grid = array_fill(0, 6, array_fill(0, 6, 9));
        foreach ([0, 1, 2] as $r) {
            $grid[$r][5] = 10;
        }
        $grid[4][0] = 0;                                        // a coin worth 3 × bet
        $grid[5][0] = 1;                                        // a ×2 multiplier
        $b = $this->board($grid);
        $b['coins'][4][0] = 3;
        $b['mults'][5][0] = 2;
        $strips = array_fill(0, 6, [9, 12, 13, 14]);           // refills that never win (no pays for 9/12–14 here)
        $steps = $tumble->invoke($handler, self::CFG, $b, $strips);
        $this->assertEqualsWithDelta((2 / 20 + 3) * 2, $steps[0]['pay'], 1e-9);
        $this->assertTrue($steps[0]['won']);
        $last = end($steps);
        $this->assertSame(0.0, $last['pay']);                    // coin and multiplier are gone
        $this->assertSame(0, array_sum(array_map('array_sum', $last['mults'])));
    }

    public function test_a_jumbo_block_falls_as_one_piece_only_as_far_as_both_columns_allow(): void
    {
        $handler = app(AmaticDinopupsHandler::class);
        $collapse = new ReflectionMethod($handler, 'collapse');
        $grid = array_fill(0, 6, array_fill(0, 6, 9));
        foreach ([[3, 0], [3, 1], [4, 0], [4, 1]] as [$r, $row]) {
            $grid[$r][$row] = 11;
        }
        $b = $this->board($grid);
        $b['blocks'] = [[3, 0, 2, 11]];
        $clear = [3 => [3 => true, 4 => true]];                 // two cells under the block on reel 4 only
        $out = $collapse->invoke($handler, $b, $clear);
        $this->assertSame([[3, 0, 2, 11]], $out['blocks']);      // reel 5 below is full: the block stays
        $this->assertSame(-1, $out['grid'][3][2]);              // the gap opens under it instead
        $this->assertSame(9, $out['grid'][3][5]);
    }
}
