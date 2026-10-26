<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use Tests\TestCase;

/**
 * Line rules of the Amatic site-import games solved from live spins: giant
 * wild blocks (Fortuna's Fruits), cell multipliers (Double Fruits), a board
 * wild multiplier (Lucky Double), runs at any position (Hot Diamonds), both
 * ways (Beauty Fairy) and a fractional wild multiplier (Hot Fruits 27).
 */
class AmaticLineRulesTest extends TestCase
{
    /** One payline along the top row; symbol 1 pays 10/50/100, symbol 2 pays 4/20/40, 7 is wild. */
    private function config(array $bonus, ?int $wild = 7): GameConfig
    {
        $pay = array_fill(0, 8, [0, 0, 0, 0, 0, 0]);
        $pay[1] = [0, 0, 0, 10, 50, 100];
        $pay[2] = [0, 0, 0, 4, 20, 40];

        return new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 3, 'symbols' => range(0, 7), 'symbol_count' => 8,
            'wild_symbol' => $wild, 'scatter_symbol' => null, 'wild_multiplier' => 1, 'min_match' => 1,
            'paytable' => $pay, 'paylines' => [[0, 0, 0, 0, 0]], 'reel_strips' => [],
            'bonus_config' => ['line_eval' => 'best', 'scatter_min' => 3] + $bonus,
        ]), new Game(['denomination' => 1]));
    }

    private function win(GameConfig $cfg, array $board, ?array $cellMults = null): float
    {
        return app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 1, cellMults: $cellMults)['win'];
    }

    public function test_a_block_symbol_covers_the_cells_around_it_clipped_at_the_edges(): void
    {
        $board = [[1, 3, 3], [3, 3, 3], [4, 7, 4], [5, 5, 5], [1, 6, 6]];

        $this->assertSame(
            [[0, 0, 0], [7, 7, 7], [7, 7, 7], [7, 7, 7], [0, 0, 0]],
            GameConfig::blockCells($board, 7, 3),
        );
        // centred on the last reel's top cell: only the 2 × 2 corner is left
        $this->assertSame([[0, 0, 0], [0, 0, 0], [0, 0, 0], [7, 7, 0], [7, 7, 0]],
            GameConfig::blockCells([[1, 1, 1], [1, 1, 1], [1, 1, 1], [1, 1, 1], [7, 1, 1]], 7, 3));

        // the top row reads 1 3 4 5 1; with the block it is 1 W W W 1 — five of symbol 1
        $this->assertSame(0.0, $this->win($this->config([]), $board));
        $this->assertSame(100.0, $this->win($this->config(['wild_block' => ['symbol' => 7, 'size' => 3]]), $board));
    }

    public function test_a_line_pays_the_product_of_its_run_cells_multipliers(): void
    {
        $cfg = $this->config(['cell_mult' => ['symbols' => [1, 2], 'values' => ['1' => 3, '2' => 1]]]);
        $board = [[1, 0, 0], [1, 0, 0], [1, 0, 0], [3, 0, 0], [1, 0, 0]];
        $mults = [[2, 1, 1], [1, 1, 1], [2, 1, 1], [2, 1, 1], [2, 1, 1]];

        // run of three 1s pays 10 × 2 × 1 × 2; reel 3 and 4's ×2 are past the run
        $this->assertSame(40.0, $this->win($cfg, $board, $mults));

        // drawn multipliers are a pure function of the board, on paying symbols only
        $drawn = $cfg->cellMultipliers($board);
        $this->assertSame($drawn, $cfg->cellMultipliers($board));
        $this->assertSame(1, $drawn[3][0]);
    }

    public function test_every_line_doubles_per_wild_anywhere_on_the_board(): void
    {
        $cfg = $this->config(['wild_board_mult' => 2]);
        $board = [[1, 7, 0], [1, 0, 0], [1, 0, 7], [3, 0, 0], [4, 0, 0]];

        $this->assertSame(4, $cfg->boardWildMultiplier($board));
        $this->assertSame(40.0, $this->win($cfg, $board));
    }

    public function test_a_run_may_start_on_any_reel(): void
    {
        $board = [[3, 0, 0], [4, 0, 0], [2, 0, 0], [2, 0, 0], [2, 0, 0]];

        $this->assertSame(0.0, $this->win($this->config([]), $board));
        $this->assertSame(4.0, $this->win($this->config(['line_any_position' => true]), $board));
    }

    public function test_both_ways_pays_the_better_direction(): void
    {
        $cfg = $this->config(['line_both_ways' => true]);

        // 1 1 1 from the left (10) and 2 2 from the right pay nothing extra
        $this->assertSame(10.0, $this->win($cfg, [[1, 0, 0], [1, 0, 0], [1, 0, 0], [2, 0, 0], [2, 0, 0]]));
        // only from the right: 2 2 2 + wild = four 2s
        $this->assertSame(20.0, $this->win($cfg, [[3, 0, 0], [2, 0, 0], [7, 0, 0], [2, 0, 0], [2, 0, 0]]));
        // 1 1 W from the left (10) and 2 2 W from the right (4): the better one, not both
        $this->assertSame(10.0, $this->win($cfg, [[1, 0, 0], [1, 0, 0], [7, 0, 0], [2, 0, 0], [2, 0, 0]]));
    }

    public function test_a_fractional_wild_factor_scales_runs_with_a_wild(): void
    {
        $cfg = $this->config(['wild_pay_factor' => 1.5]);

        $this->assertSame(15.0, $this->win($cfg, [[1, 0, 0], [7, 0, 0], [1, 0, 0], [3, 0, 0], [3, 0, 0]]));
        $this->assertSame(10.0, $this->win($cfg, [[1, 0, 0], [1, 0, 0], [1, 0, 0], [3, 0, 0], [3, 0, 0]]));
    }
}
