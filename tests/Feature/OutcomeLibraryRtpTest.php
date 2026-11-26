<?php

namespace Tests\Feature;

use App\Models\ApolloRound;
use App\Models\BooongoRound;
use App\Models\EgtRound;
use App\Models\IgtRound;
use App\Models\MerkurRound;
use App\Models\MicrogamingRound;
use App\Models\NetEntRound;
use App\Models\NovolineRound;
use App\Models\PlayNGoRound;
use App\Models\PlaytechRound;
use App\Models\PragmaticRound;
use App\Models\WazdanRound;
use App\Services\GamePlay\Apollo\ApolloLibrary;
use App\Services\GamePlay\Booongo\BooongoLibrary;
use App\Services\GamePlay\Egt\EgtLibrary;
use App\Services\GamePlay\Igt\IgtLibrary;
use App\Services\GamePlay\Merkur\MerkurLibrary;
use App\Services\GamePlay\Microgaming\MicrogamingLibrary;
use App\Services\GamePlay\NetEnt\NetEntLibrary;
use App\Services\GamePlay\Novoline\NovolineLibrary;
use App\Services\GamePlay\PlayNGo\PlayNGoLibrary;
use App\Services\GamePlay\Playtech\PlaytechLibrary;
use App\Services\GamePlay\PragmaticPlay\PragmaticLibrary;
use App\Services\GamePlay\ReturnSteering;
use App\Services\GamePlay\Wazdan\WazdanLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The recorded-round libraries steer their return to the shop's RTP target
 * both ways ({@see ReturnSteering}): down (wins swapped for losses) and up
 * (losses swapped for recorded wins), jackpot-sized rounds held to a few points.
 */
class OutcomeLibraryRtpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The steering itself, on a library of [stake, win] rows: the return lands on
     * the target below, at and above the library's own.
     */
    public function test_steering_lands_on_the_target_both_ways(): void
    {
        // 60% library, 20% hit: 8 losing rounds, 2 paying 3 stakes
        $this->assertReturns([[100, 0], [100, 0], [100, 0], [100, 0], [100, 0], [100, 0], [100, 0], [100, 0], [100, 300], [100, 300]], [40, 60, 96, 120]);
    }

    public function test_a_jackpot_round_adds_at_most_a_few_points(): void
    {
        // 999 rounds returning ~50%, and one paying 5000 stakes — alone it would make the library ~550%
        $rows = [];
        for ($i = 0; $i < 999; $i++) {
            $rows[] = [100, $i % 4 === 0 ? 200 : 0];
        }
        $rows[] = [100, 500000];
        $stats = $this->stats($rows);
        $this->assertGreaterThan(500, $stats['rtp']);
        $this->assertEqualsWithDelta(500.0, $stats['rare_rtp'], 0.1);

        $this->assertReturns($rows, [90, 96], 2_000_000, 1.5);
    }

    /** @return array<string, array{0: class-string, 1: class-string, 2: array<string, mixed>, 3: string, 4: string, 5: int|string}> */
    public static function libraries(): array
    {
        $cents = ['bet_cents', 'win_cents'];
        $credit = ['start_credit' => 10000];

        return [
            'apollo' => [ApolloLibrary::class, ApolloRound::class, $credit, ...$cents, 'g'],
            'microgaming' => [MicrogamingLibrary::class, MicrogamingRound::class, $credit, ...$cents, 'g'],
            'booongo' => [BooongoLibrary::class, BooongoRound::class, $credit, ...$cents, 'g'],
            'merkur' => [MerkurLibrary::class, MerkurRound::class, $credit, ...$cents, 'g'],
            'novoline' => [NovolineLibrary::class, NovolineRound::class, $credit, ...$cents, 'g'],
            'pragmatic' => [PragmaticLibrary::class, PragmaticRound::class, $credit, ...$cents, 'g'],
            'playtech' => [PlaytechLibrary::class, PlaytechRound::class, $credit, ...$cents, 'g'],
            'igt' => [IgtLibrary::class, IgtRound::class, $credit, ...$cents, 'g'],
            'wazdan' => [WazdanLibrary::class, WazdanRound::class, $credit, ...$cents, 'g'],
            'egt' => [EgtLibrary::class, EgtRound::class, ['start_balance' => 10000], ...$cents, 7],
            'netent' => [NetEntLibrary::class, NetEntRound::class, $credit, 'bet_coins', 'win_coins', 'g'],
            'playngo' => [PlayNGoLibrary::class, PlayNGoRound::class, [], 'bet_coins', 'win_coins', 'g'],
        ];
    }

    /**
     * Each library is wired to the steering: a 0% target never serves a win, an
     * unreachable one serves ordinary wins in place of losses, and the max-win cap still holds.
     */
    #[DataProvider('libraries')]
    public function test_each_library_steers_both_ways(string $library, string $model, array $extra, string $bet, string $win, int|string $game): void
    {
        $key = $model === EgtRound::class ? 'gin' : 'game_key';
        // 60% library: 8 losing rounds, 2 paying 3 stakes, one jackpot-sized (5000 stakes) on top
        foreach ([0, 0, 0, 0, 0, 0, 0, 0, 300, 300, 500000] as $w) {
            $model::query()->create([$key => $game, $bet => 100, $win => $w, 'steps' => []] + $extra);
        }
        $lib = app($library);
        $stats = $lib->stats($game);
        $this->assertEqualsWithDelta(0.2 * 10 / 11, $stats['hit'], 0.001);
        $this->assertGreaterThan(0, $stats['rare_rtp']);

        for ($i = 0; $i < 60; $i++) {
            $this->assertSame(0, (int) $lib->pick($game, 0, PHP_INT_MAX)->{$win}, 'a 0% target serves no win');
        }
        $won = 0;
        for ($i = 0; $i < 60; $i++) {
            $paid = (int) $lib->pick($game, 100000, PHP_INT_MAX)->{$win};
            $won += $paid > 0 ? 1 : 0;
            $this->assertNotSame(500000, $paid, 'no jackpot pulled in to lift the return');
        }
        // every losing draw becomes a win; a drawn jackpot (1 in 11) is swapped for a loss — ~55 of 60
        $this->assertGreaterThanOrEqual(45, $won, 'an unreachable target serves (almost) only wins');
        for ($i = 0; $i < 60; $i++) {
            $this->assertLessThanOrEqual(299, (int) $lib->pick($game, 100000, 299)->{$win}, 'the cap holds');
        }
    }

    /**
     * Serve `$draws` spins from `$rows` through {@see ReturnSteering::decide()} and check the return at each target.
     *
     * @param  list<array{0: int, 1: int}>  $rows
     * @param  list<int>  $targets
     */
    private function assertReturns(array $rows, array $targets, int $draws = 300_000, float $delta = 1.0): void
    {
        $stats = $this->stats($rows);
        $losing = array_values(array_filter($rows, fn ($r) => $r[1] === 0));
        $ordinary = array_values(array_filter($rows, fn ($r) => $r[1] > 0 && $r[1] <= ReturnSteering::RARE_MULTIPLIER * $r[0]));
        mt_srand(11);
        foreach ($targets as $target) {
            $staked = $paid = 0;
            for ($i = 0; $i < $draws; $i++) {
                $r = $rows[mt_rand(0, count($rows) - 1)];
                $r = match (ReturnSteering::decide($stats, $target, $r[1], $r[0])) {
                    ReturnSteering::LOSE => $losing[mt_rand(0, count($losing) - 1)],
                    ReturnSteering::WIN => $ordinary[mt_rand(0, count($ordinary) - 1)],
                    default => $r,
                };
                $staked += $r[0];
                $paid += $r[1];
            }
            $this->assertEqualsWithDelta($target, 100 * $paid / $staked, $delta, "target {$target}%");
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $rows
     * @return array{rtp: float, rare_rtp: float, hit: float}
     */
    private function stats(array $rows): array
    {
        $staked = array_sum(array_column($rows, 0));
        $rare = array_filter($rows, fn ($r) => $r[1] > ReturnSteering::RARE_MULTIPLIER * $r[0]);
        $hits = array_filter($rows, fn ($r) => $r[1] > 0 && $r[1] <= ReturnSteering::RARE_MULTIPLIER * $r[0]);

        return [
            'rtp' => 100 * array_sum(array_column($rows, 1)) / $staked,
            'rare_rtp' => 100 * array_sum(array_column($rare, 1)) / $staked,
            'hit' => count($hits) / count($rows),
        ];
    }
}
