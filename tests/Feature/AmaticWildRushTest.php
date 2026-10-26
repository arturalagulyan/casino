<?php

namespace Tests\Feature;

use App\Services\GamePlay\Protocol\AmaticWildRushHandler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WildCat WildRush: nudging wilds, per-reel lights and sticky reels (rules
 * checked against 400 live spins), and the bonus's adding wild multipliers.
 */
class AmaticWildRushTest extends TestCase
{
    private const array CFG = [
        'rows' => 4, 'scatter' => 13, 'substitutes' => [14, 15, 16, 17, 18], 'top' => 1, 'wild' => 14, 'bonus_wild' => 15, 'wild_max' => 18,
        'sticky_after' => 3, 'sticky_spins' => 3, 'bet_factor' => 20,
        'lines' => [[0, 0, 0, 0, 0, 0], [3, 3, 3, 3, 3, 3]],
        'pays' => [1 => [0, 0, 50, 100, 200, 500], 5 => [0, 0, 15, 30, 60, 100], 14 => [0, 0, 50, 100, 200, 500]],
    ];

    private function plain(int $sym = 5): array
    {
        return array_fill(0, 6, array_fill(0, 4, $sym));
    }

    private function fresh(): array
    {
        return ['cells' => $this->plain(0), 'w' => $this->plain(0), 'lights' => array_fill(0, 6, 0), 'sticky' => 0];
    }

    public function test_a_wild_nudges_down_lights_its_reel_and_the_third_makes_it_sticky_for_three_spins(): void
    {
        $handler = app(AmaticWildRushHandler::class);
        $advance = new ReflectionMethod($handler, 'advance');
        $grid = $this->plain(9);
        $grid[2][0] = 14;
        $b = $advance->invoke($handler, self::CFG, $this->fresh(), $grid, false);
        $this->assertSame(14, $b['w'][2][0]);
        $this->assertSame(1, $b['lights'][2]);

        $b = $advance->invoke($handler, self::CFG, $b, $this->plain(9), false);
        $this->assertSame(14, $b['w'][2][1]);                 // nudged a row down
        $this->assertSame(0, $b['cells'][2][1]);               // the cell under it shows empty
        $this->assertSame(1, $b['lights'][2]);                 // nudging wilds don't count

        $grid = $this->plain(9);
        $grid[2][3] = 14;
        $grid[2][0] = 14;
        $b = $advance->invoke($handler, self::CFG, $b, $grid, false);
        $this->assertSame(1 << 2, $b['sticky']);                // the 3rd wild: the reel goes sticky
        $this->assertSame(2, $b['lights'][2]);
        $this->assertSame([0, 0, 0, 0], $b['w'][2]);

        foreach ([1, 0] as $left) {                             // counts down 2 → 1 → 0 …
            $b = $advance->invoke($handler, self::CFG, $b, $this->plain(9), false);
            $this->assertSame(1 << 2, $b['sticky']);
            $this->assertSame($left, $b['lights'][2]);
        }
        $b = $advance->invoke($handler, self::CFG, $b, $this->plain(9), false);
        $this->assertSame(0, $b['sticky']);                     // … and is released after 0
        $this->assertSame(0, $b['lights'][2]);
    }

    public function test_bonus_wilds_land_x2_grow_per_nudge_and_a_sticky_reel_stays(): void
    {
        $handler = app(AmaticWildRushHandler::class);
        $advance = new ReflectionMethod($handler, 'advance');
        $grid = $this->plain(9);
        $grid[0][0] = 14;
        $b = $advance->invoke($handler, self::CFG, $this->fresh(), $grid, true);
        $this->assertSame(15, $b['w'][0][0]);
        for ($row = 1; $row < 4; $row++) {
            $b = $advance->invoke($handler, self::CFG, $b, $this->plain(9), true);
            $this->assertSame(15 + $row, $b['w'][0][$row]);     // ×3, ×4, ×5
        }
        $b = $advance->invoke($handler, self::CFG, $b, $this->plain(9), true);
        $this->assertSame([0, 0, 0, 0], $b['w'][0]);            // gone off the bottom

        $sticky = ['sticky' => 1 << 4, 'lights' => [0, 0, 0, 0, 2, 0]] + $this->fresh();
        for ($k = 0; $k < 6; $k++) {
            $sticky = $advance->invoke($handler, self::CFG, $sticky, $this->plain(9), true);
            $this->assertSame(1 << 4, $sticky['sticky']);
        }
    }

    public function test_the_x2_and_up_wilds_on_a_line_add_their_multipliers(): void
    {
        $handler = app(AmaticWildRushHandler::class);
        $pays = new ReflectionMethod($handler, 'linePays');
        $grid = $this->plain(9);
        foreach ([0, 1, 2] as $r) {
            $grid[$r][0] = 5;
        }
        $this->assertSame([15.0, 0.0], $pays->invoke($handler, self::CFG, $grid));
        $grid[1][0] = 14;                                       // a plain wild: ×1
        $this->assertSame([15.0, 0.0], $pays->invoke($handler, self::CFG, $grid));
        $grid[1][0] = 16;                                       // ×3
        $grid[3][0] = 15;                                       // ×2 extends the run to 4 → (3 + 2) × 30
        $this->assertSame([150.0, 0.0], $pays->invoke($handler, self::CFG, $grid));
    }
}
