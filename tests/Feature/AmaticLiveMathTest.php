<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\GamePlay\Amatic\AmaticClientMath;
use App\Services\GamePlay\Amatic\AmaticFrameDecoder;
use App\Services\GamePlay\Amatic\AmaticMathSolver;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\Protocol\AmaticFormatter;
use Tests\TestCase;

/**
 * Maths settled against Amatic's live server: the legacy Amatic line rules
 * the engine now supports, reading Amatic's frames, pulling the paytable out
 * of a client engine, and the solver that ties them together.
 */
class AmaticLiveMathTest extends TestCase
{
    /** 5×3, 3 lines (middle, top, bottom), symbols 0..5. */
    private function cfg(array $bonus = [], ?int $wild = 0, ?int $scatter = 5, int $wildMult = 1): GameConfig
    {
        return new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 3, 'symbols' => range(0, 5), 'min_match' => 1,
            'wild_symbol' => $wild, 'scatter_symbol' => $scatter, 'wild_multiplier' => $wildMult,
            'paytable' => [
                0 => [0, 0, 10, 100, 1000, 5000],   // wild pays on its own
                1 => [0, 0, 0, 20, 50, 200],
                2 => [0, 0, 0, 10, 20, 100],
                3 => [0, 0, 0, 5, 10, 50],
                4 => [0, 0, 0, 5, 10, 50],
                5 => [0, 0, 2, 5, 20, 100],         // scatter
            ],
            'paylines' => [[1, 1, 1, 1, 1], [0, 0, 0, 0, 0], [2, 2, 2, 2, 2]],
            'reel_strips' => [],
            'bonus_config' => $bonus,
        ]), new Game(['denomination' => 1]));
    }

    /** @param list<list<int>> $rows [row][reel] */
    private function evaluate(GameConfig $cfg, array $rows): array
    {
        $board = [];
        foreach ($rows as $d => $row) {
            foreach ($row as $r => $sym) {
                $board[$r][$d] = $sym;
            }
        }

        return app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 3);
    }

    public function test_best_line_rule_pays_the_wild_run_when_it_beats_the_symbol_run(): void
    {
        // middle line: W W 1 2 3 → "first non-wild" reads 1×3 (20); Amatic also
        // reads the wilds' own 2-run (10) and the best is still 20 …
        $rows = [[3, 4, 3, 4, 3], [0, 0, 1, 2, 3], [4, 3, 4, 3, 4]];
        $this->assertEqualsWithDelta(20, $this->evaluate($this->cfg(['line_eval' => 'best']), $rows)['win'], 0.001);

        // … but W W W 4 1: symbol 4×4 pays 10, the wilds' own 3-run pays 100
        $rows = [[3, 4, 3, 4, 3], [0, 0, 0, 4, 1], [4, 3, 4, 3, 4]];
        $this->assertEqualsWithDelta(10, $this->evaluate($this->cfg(), $rows)['win'], 0.001);
        $this->assertEqualsWithDelta(100, $this->evaluate($this->cfg(['line_eval' => 'best']), $rows)['win'], 0.001);
    }

    public function test_wild_multiplier_applies_only_to_runs_mixing_wilds_and_symbols(): void
    {
        $cfg = $this->cfg(['line_eval' => 'best'], wildMult: 2);
        $this->assertEqualsWithDelta(40, $this->evaluate($cfg, [[3, 4, 3, 4, 3], [1, 0, 1, 2, 3], [4, 3, 4, 3, 4]])['win'], 0.001);
        $this->assertEqualsWithDelta(20, $this->evaluate($cfg, [[3, 4, 3, 4, 3], [1, 1, 1, 2, 3], [4, 3, 4, 3, 4]])['win'], 0.001);
    }

    public function test_expanding_wild_fills_its_reel_before_lines_are_read(): void
    {
        // the wild sits on the top row of reel 2; expanded it completes 2-2-2 on the bottom line
        $rows = [[3, 0, 4, 3, 4], [4, 3, 3, 4, 3], [2, 4, 2, 3, 4]];
        $this->assertEqualsWithDelta(0, $this->evaluate($this->cfg(['line_eval' => 'best']), $rows)['win'], 0.001);
        $this->assertEqualsWithDelta(10, $this->evaluate($this->cfg(['line_eval' => 'best', 'expanding_wild' => true]), $rows)['win'], 0.001);
    }

    public function test_two_scatters_pay_when_configured_and_a_wild_doubles_the_scatter_win(): void
    {
        $rows = [[5, 3, 4, 3, 4], [4, 3, 3, 4, 5], [3, 4, 3, 4, 3]];
        $this->assertSame(0.0, $this->evaluate($this->cfg(), $rows)['win']);
        // 2 scatters → 2 × total bet (3 lines)
        $this->assertEqualsWithDelta(6, $this->evaluate($this->cfg(['scatter_min' => 2]), $rows)['win'], 0.001);

        $withWild = [[5, 3, 4, 3, 4], [4, 3, 3, 4, 5], [3, 4, 0, 4, 3]];
        $this->assertEqualsWithDelta(12, $this->evaluate($this->cfg(['scatter_min' => 2, 'scatter_wild_mult' => 2, 'line_eval' => 'best']), $withWild)['win'], 0.001);
    }

    public function test_tall_symbol_pieces_count_as_their_symbol(): void
    {
        // symbols 6/7 are the upper pieces of a 3-high wild (0)
        $cfg = $this->cfg(['line_eval' => 'best', 'symbol_alias' => ['6' => 0, '7' => 0]]);
        $rows = [[3, 6, 4, 3, 4], [1, 7, 1, 4, 3], [4, 0, 3, 4, 3]];
        $this->assertEqualsWithDelta(20, $this->evaluate($cfg, $rows)['win'], 0.001);
    }

    public function test_decoder_reads_extended_init_with_two_digit_symbols(): void
    {
        $strip = array_merge(range(0, 16), range(0, 16));
        $hex = implode('', array_map(fn ($s) => sprintf('%02x', $s), $strip));
        $strips = str_repeat('2'.sprintf('%02x', count($strip)).$hex, 5);
        $frame = '-10'.'5'.$strips.'0'.'03'.'0'.'05'.str_repeat('10', 5)
            .'10'.'530d40'.'10'.'00'.'1a'.'33e8'.'64'.str_repeat('10', 7).'64'.'64'.'0a'.'11'.'00'.'05'.str_repeat('10', 5)
            .str_repeat('00', 8).'02'.'11'.'12'.'02'.'10'.'10'.'0f|97%#00101010|0';

        $init = (new AmaticFrameDecoder)->init($frame);

        $this->assertNotNull($init);
        $this->assertSame(2, $init['width']);
        $this->assertSame($strip, $init['strips'][0]);
        $this->assertSame(100, $init['lines']);
        $this->assertSame([1, 2], $init['bets']);
        $this->assertSame('0f|97%#00101010|0', $init['rest']);
    }

    public function test_client_math_takes_the_paylines_matching_the_line_count_and_the_table_before_them(): void
    {
        $js = 'this.a=[[2,2,2,2,2],[1,1,1,1,1],[3,3,3,3,3]];'                    // generic 3-line default
            .'this.b=[[0,0,40,200,1E3],[0,0,20,50,200],[0,0,5,10,50]];this.c=[0,1,1,2];'
            .'this.d=[[2,2,2,2,2],[1,1,1,1,1],[3,3,3,3,3],[1,2,3,2,1]];';

        $math = (new AmaticClientMath)->extract($js, 5, 4);

        $this->assertNotNull($math);
        $this->assertEquals([0, 0, 40, 200, 1000], $math['paytable'][0]);
        $this->assertSame([0, 1, 2, 1, 0], $math['paylines'][3]);
        $this->assertSame(3, $math['rows']);
        $this->assertContains([0, 1, 1, 2], $math['maps']);
    }

    public function test_solver_recovers_wild_and_multiplier_from_live_line_wins(): void
    {
        $strip = [1, 2, 3, 4, 0, 1, 2, 3, 4, 2, 1, 3, 0, 4, 2, 1, 4, 3];
        $strips = array_fill(0, 5, $strip);
        $client = [
            'paytable' => [[0, 2, 10, 100, 1000], [0, 0, 20, 50, 200], [0, 0, 10, 20, 100], [0, 0, 5, 10, 50], [0, 0, 5, 10, 50]],
            'paylines' => [[1, 1, 1, 1, 1], [0, 0, 0, 0, 0], [2, 2, 2, 2, 2]],
            'rows' => 3,
            'maps' => [],
        ];
        // ground truth: wild 0 doubling the wins it completes
        $truth = new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 3, 'symbols' => range(0, 4), 'min_match' => 1,
            'wild_symbol' => 0, 'scatter_symbol' => null, 'wild_multiplier' => 2,
            'paytable' => array_map(fn ($r) => [0, ...$r], $client['paytable']),
            'paylines' => $client['paylines'], 'reel_strips' => [],
            'bonus_config' => ['line_eval' => 'best'],
        ]), new Game(['denomination' => 1]));
        $spins = [];
        for ($k = 0; $k < 60; $k++) {
            $rp = array_map(fn () => random_int(0, count($strip) - 1), range(0, 4));
            $board = [];
            foreach ($strips as $r => $s) {
                for ($d = 0; $d < 3; $d++) {
                    $board[$r][$d] = $s[(($rp[$r] - 1 + $d) % 18 + 18) % 18];
                }
            }
            $ev = app(SlotEngine::class)->evaluate($board, $truth, 1.0, 3);
            $wins = [0, 0, 0, 0];
            foreach ($ev['lines'] as $w) {
                $wins[$w['line']] += (int) $w['amount'];
            }
            $spins[] = ['state' => 9, 'win' => (int) $ev['win'], 'rp' => $rp, 'lines' => 3, 'line_wins' => $wins];
        }
        $init = ['ext' => 1, 'width' => 1, 'strips' => $strips, 'free_strips' => [], 'lines' => 3, 'bets' => [1], 'win_slots' => 4, 'rest' => '|#'];

        $solved = app(AmaticMathSolver::class)->solve($init, $spins, $client);

        $this->assertNotNull($solved);
        $this->assertSame(0, $solved['rules']['wild']);
        $this->assertSame(2, $solved['rules']['wild_multiplier']);
    }

    public function test_ways_solver_recovers_the_doubler_and_scatter_from_per_symbol_wins(): void
    {
        $strip = [0, 1, 2, 9, 3, 0, 4, 10, 1, 2, 3, 4, 0, 9, 2, 1, 3, 4, 0, 2];
        $strips = array_fill(0, 5, $strip);
        $table = [[0, 0, 10, 50, 500], [0, 0, 7, 25, 100], [0, 0, 5, 10, 20], [0, 0, 3, 5, 10], [0, 0, 2, 4, 8],
            [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 0, 0, 0], [0, 0, 5, 25, 50]];
        $solver = app(AmaticMathSolver::class);
        $truth = ['reels' => 5, 'rows' => 3, 'lines' => 9, 'symbols' => range(0, 10),
            'paytable' => array_map(fn ($s) => isset($table[$s]) ? [0, ...$table[$s]] : array_fill(0, 6, 0), range(0, 10)),
            'paylines' => null, 'wild' => null, 'scatter' => 9, 'ways' => ['symbols' => range(0, 8), 'doubler' => 10, 'free_any_reel' => false]];
        $cfg = new GameConfig(new GameTemplate($solver->waysTemplate($truth)), new Game(['denomination' => 1]));
        $spins = [];
        for ($k = 0; $k < 60; $k++) {
            $rp = array_map(fn () => random_int(0, 19), range(0, 4));
            $board = [];
            foreach ($strips as $r => $s) {
                for ($d = 0; $d < 3; $d++) {
                    $board[$r][$d] = $s[(($rp[$r] - 1 + $d) % 20 + 20) % 20];
                }
            }
            $wins = array_fill(0, 10, 0);
            foreach (app(SlotEngine::class)->evaluate($board, $cfg, 1.0, 1)['lines'] as $w) {
                $wins[$w['line'] < 0 ? 9 : $w['line']] += (int) round($w['amount']);
            }
            $spins[] = ['state' => 9, 'win' => array_sum($wins), 'rp' => $rp, 'lines' => 9, 'line_wins' => $wins];
        }
        $init = ['ext' => 1, 'width' => 1, 'strips' => $strips, 'free_strips' => [], 'lines' => 9, 'bets' => [1], 'win_slots' => 10, 'rest' => '|#'];

        $solved = $solver->solveWays($init, $spins, $table);

        $this->assertNotNull($solved);
        $this->assertSame(9, $solved['rules']['scatter']);
        $this->assertSame(10, $solved['rules']['ways']['doubler']);
    }

    public function test_overlay_games_send_every_strip_and_park_the_overlay(): void
    {
        $cfg = new GameConfig(new GameTemplate([
            'reel_count' => 5, 'row_count' => 3,
            'reel_strips' => array_combine(array_map(fn ($i) => "reelStrip{$i}", range(1, 5)), array_fill(0, 5, [1, 2, 3])),
            'bonus_config' => ['client' => ['digits' => 2, 'overlay_strips' => array_fill(0, 5, [11, 11, 11, 12]), 'overlay_rp' => [1, 1, 1, 1, 1]]],
        ]), new Game(['denomination' => 1]));
        $fmt = app(AmaticFormatter::class);

        $strips = $fmt->reelStrips($cfg, false);
        $this->assertSame(str_repeat('13010203', 5), $strips);
        $positions = (new \ReflectionMethod($fmt, 'positions'))->invoke($fmt, $cfg, [2, 2, 2, 2, 2]);
        $this->assertSame(str_repeat('12', 5).str_repeat('11', 5), $positions);
    }

    public function test_formatter_pads_three_reel_games_to_five_strip_slots(): void
    {
        $cfg = new GameConfig(new GameTemplate([
            'reel_count' => 3, 'row_count' => 3,
            'reel_strips' => ['reelStrip1' => [1, 2, 3], 'reelStrip2' => [1, 2, 3], 'reelStrip3' => [1, 2, 3]],
            'bonus_config' => ['client' => ['digits' => 2]],
        ]), new Game(['denomination' => 1]));

        $this->assertSame(str_repeat('13010203', 3).'00', app(AmaticFormatter::class)->reelStrips($cfg, false));
    }
}
