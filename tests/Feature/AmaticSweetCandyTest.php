<?php

namespace Tests\Feature;

use App\Services\GamePlay\Protocol\AmaticSweetCandyHandler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WildCat SweetCandyMegaDrops: pay-anywhere tiers with wilds, the bottom
 * multiplier, and MEGA DROPS (the first full reel pays its sum).
 */
class AmaticSweetCandyTest extends TestCase
{
    private const array CFG = [
        'wild' => 13, 'scatter' => 14, 'rocket' => 16, 'bomb' => 17, 'tiers' => [8, 10, 12], 'pay_unit' => 10,
        'pays' => [10 => [0, 0, 3, 7, 10], 11 => [0, 0, 2, 6, 8]],
        'multiplier_rate' => 0.0, 'multiplier_values' => [2 => 1], 'rocket_dirs' => [6 => 1],
    ];

    public function test_a_symbol_pays_from_eight_anywhere_with_the_wilds_joining(): void
    {
        $handler = app(AmaticSweetCandyHandler::class);
        $evaluate = new ReflectionMethod($handler, 'evaluate');
        $grid = array_fill(0, 5, array_fill(0, 7, 1));
        foreach ([[0, 0], [1, 1], [2, 2], [3, 3], [4, 4], [0, 5], [1, 6]] as [$r, $row]) {
            $grid[$r][$row] = 10;                               // 7 × symbol 10
        }
        [$raw] = $evaluate->invoke($handler, self::CFG, $grid);
        $this->assertSame(0.0, $raw);
        $grid[2][6] = 13;                                       // a wild makes it 8
        [$raw, $clear, $won] = $evaluate->invoke($handler, self::CFG, $grid);
        $this->assertEqualsWithDelta(3 / 10, $raw, 1e-9);
        $this->assertSame([10 => 8], $won);
        $this->assertTrue($clear[2][6]);                         // the wild goes with it
    }

    public function test_the_bottom_multiplier_multiplies_a_winning_step(): void
    {
        $handler = app(AmaticSweetCandyHandler::class);
        $steps = new ReflectionMethod($handler, 'spinSteps');
        $strips = array_fill(0, 5, array_merge(array_fill(0, 4, 10), array_fill(0, 30, 1)));   // the window may hold 10s
        $cfg = ['multiplier_rate' => 0.5, 'multiplier_values' => [3 => 1]] + self::CFG;
        $multiplied = 0;
        for ($i = 0; $i < 200; $i++) {
            foreach ($steps->invoke($handler, $cfg, $strips) as $s) {
                $bottom = (int) $s['mults'][6];
                $this->assertEqualsWithDelta($s['raw'] * max(1, $bottom), $s['pay'], 1e-9);
                $multiplied += $s['raw'] > 0 && $bottom > 0 ? 1 : 0;
            }
        }
        $this->assertGreaterThan(0, $multiplied);
    }

    public function test_mega_drops_pay_the_first_full_reel(): void
    {
        $handler = app(AmaticSweetCandyHandler::class);
        $cfg = ['mega_rounds' => [3 => 2], 'mega_values' => [1 => 1, 5 => 1], 'mega_drop_rate' => 0.6];
        $mega = $handler->megaDrops($cfg, 3);
        $this->assertCount(2, $mega['spins']);
        foreach ($mega['spins'] as $round) {
            $full = array_values(array_filter($round['values'], fn ($col) => ! in_array(0, $col, true)));
            $this->assertCount(1, $full);                       // exactly one reel fills up
            $this->assertEqualsWithDelta(array_sum($full[0]), $round['win'], 1e-9);
            $this->assertSame(0, count($round['bits']) % 5);    // 5 bits (reels) per drop step
        }
    }
}
