<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use Tests\TestCase;

/**
 * Rules of the last Amatic site-import round, solved from live spins:
 * per-reel wild multipliers summed over a line (Kitty Bet), a mystery symbol
 * drawn per reel group (Big Panda), "any mix" pays a wild multiplies (Lucky
 * Zodiac), and rombo ways games with tall wilds (Grand Tiger, Golden Book).
 */
class AmaticFeatureRulesTest extends TestCase
{
    /** One payline along the top row; symbol 1 pays 10/50/100, symbol 2 pays 4/20/40, 7 is wild. */
    private function lines(array $bonus, ?int $wild = 7, array $extraRows = []): GameConfig
    {
        $pay = array_fill(0, 8, [0, 0, 0, 0, 0, 0]);
        $pay[1] = [0, 0, 0, 10, 50, 100];
        $pay[2] = [0, 0, 0, 4, 20, 40];

        return new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 3, 'symbols' => range(0, 7), 'symbol_count' => 8,
            'wild_symbol' => $wild, 'scatter_symbol' => null, 'wild_multiplier' => 2, 'min_match' => 1,
            'paytable' => $pay + $extraRows, 'paylines' => [[0, 0, 0, 0, 0]], 'reel_strips' => [],
            'bonus_config' => ['line_eval' => 'best', 'scatter_min' => 3] + $bonus,
        ]), new Game(['denomination' => 1]));
    }

    public function test_a_line_pays_the_sum_of_its_wild_reels_multipliers(): void
    {
        $cfg = $this->lines(['wild_reel_values' => ['values' => ['2' => 1, '3' => 1], 'prefix' => 'p', 'suffix' => 's']]);
        $board = [[1, 0, 0], [7, 0, 0], [7, 0, 0], [3, 0, 0], [0, 0, 0]];

        // 1 W W: three 1s × (reel 2's 2 + reel 3's 3)
        $this->assertSame(50.0, app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 1, reelValues: [2, 2, 3, 3, 2])['win']);

        // the drawn values are a pure function of the board, one per reel, from the configured values
        $drawn = $cfg->wildReelDraw($board);
        $this->assertSame($drawn, $cfg->wildReelDraw($board));
        $this->assertCount(5, $drawn);
        $this->assertSame([], array_diff($drawn, [2, 3]));
    }

    public function test_each_reel_group_draws_its_own_mystery_symbol(): void
    {
        $cfg = $this->lines(['mystery' => ['symbol' => 6, 'targets' => ['1' => 1, '2' => 1, '3' => 1], 'groups' => [[1, 2], [3, 4]], 'suffix' => '15']]);
        $board = [[0, 0, 0], [6, 6, 6], [6, 6, 6], [6, 6, 6], [6, 6, 6]];

        $targets = $cfg->mysteryReelTargets($board);
        $this->assertNull($targets[0]);
        $this->assertSame($targets[1], $targets[2]);
        $this->assertSame($targets[3], $targets[4]);
    }

    public function test_a_wild_in_an_any_mix_run_multiplies_it_when_the_game_says_so(): void
    {
        $mixed = ['mixed_pays' => [['symbols' => [1, 2], 'row' => 8]]];
        $rows = [8 => [0, 0, 0, 0, 0, 75]];
        // 1 2 W 1 2: no line of one symbol, five of the {1, 2} mix
        $board = [[1, 0, 0], [2, 0, 0], [7, 0, 0], [1, 0, 0], [2, 0, 0]];

        $this->assertSame(75.0, app(SlotEngine::class)->evaluate($board, $this->lines($mixed, 7, $rows), 1.0, 1)['win']);
        $this->assertSame(150.0, app(SlotEngine::class)->evaluate($board, $this->lines($mixed + ['mixed_wild_mult' => true], 7, $rows), 1.0, 1)['win']);
    }

    public function test_rombo_ways_count_each_reels_own_height_and_tall_wild_pieces(): void
    {
        $pay = array_fill(0, 12, [0, 0, 0, 0, 0, 0]);
        $pay[0] = [0, 0, 0, 1, 2, 5];
        $pay[9] = [0, 0, 1, 5, 25, 50];
        $cfg = new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 5, 'symbols' => range(0, 11), 'symbol_count' => 12,
            'wild_symbol' => 10, 'scatter_symbol' => 9, 'wild_multiplier' => 1, 'min_match' => 1,
            'paytable' => $pay, 'paylines' => null, 'reel_strips' => [],
            'bonus_config' => ['ways' => ['symbols' => range(0, 8), 'doubler' => null, 'free_any_reel' => false],
                'reel_heights' => [3, 4, 5, 4, 3], 'symbol_alias' => ['11' => 10], 'scatter_min' => 3],
        ]), new Game(['denomination' => 1]));

        $this->assertSame(3, $cfg->reelHeight(0));
        $this->assertSame(5, $cfg->reelHeight(2));

        // symbol 0: 1 × (wild pieces 10 + 11: 2) × 2 = 4 ways of three, 1 each; two scatters don't pay
        $board = [[0, 9, 3], [10, 11, 4, 5], [0, 0, 9, 4, 5], [1, 2, 3, 4], [5, 6, 7]];
        $this->assertSame(4.0, app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 1)['win']);
    }

    public function test_a_full_wild_reel_copies_itself_onto_another_middle_reel(): void
    {
        $pay = array_fill(0, 12, [0, 0, 0, 0, 0, 0]);
        $pay[0] = [0, 0, 0, 1, 2, 5];
        $cfg = new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 5, 'symbols' => range(0, 11), 'symbol_count' => 12,
            'wild_symbol' => 10, 'scatter_symbol' => null, 'wild_multiplier' => 1, 'min_match' => 1,
            'paytable' => $pay, 'paylines' => null, 'reel_strips' => [],
            'bonus_config' => ['ways' => ['symbols' => range(0, 8), 'doubler' => null, 'free_any_reel' => false],
                'reel_heights' => [3, 4, 5, 4, 3], 'symbol_alias' => ['11' => 10], 'wild_reel_copy' => ['reels' => [1, 2, 3]]],
        ]), new Game(['denomination' => 1]));
        // reel 1 is all wild (pieces 10/11); no 0 on reels 2–4
        $board = [[0, 5, 6], [10, 11, 11, 11], [1, 2, 3, 4, 5], [1, 2, 3, 4], [0, 5, 6]];

        $target = $cfg->wildCopyTarget($board);
        $this->assertContains($target, [2, 3]);
        // copied onto reel 2: 0 · W(4) · W(5) = 20 ways of three; onto reel 3 the run stops at reel 2
        $this->assertSame(20.0, app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 1, copyReel: 2)['win']);
        $this->assertSame(0.0, app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 1, copyReel: 3)['win']);
        $this->assertNull($cfg->wildCopyTarget([[0, 5, 6], [10, 4, 11, 11], [1, 2, 3, 4, 5], [1, 2, 3, 4], [0, 5, 6]]));
    }
}
