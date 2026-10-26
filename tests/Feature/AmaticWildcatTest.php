<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Engine\CascadeEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\SocketServer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Amatic WildCat Gaming "tumble" games (AnubisGold): pay-anywhere tumbles
 * played by AmaticWildcatHandler on the shared Amatic socket.
 */
class AmaticWildcatTest extends TestCase
{
    use RefreshDatabase;

    /** Amatic's own AnubisGold init frame (free play, balance 200000). */
    private const string INIT = '-10610101010101061010101010100300610101010101010530d40100021433e80a101010101010100a0a0a11000610101010101000000000000000000d11121314151a1f21421921e22822d2320c1010101010101010101010101410132642c8312c1139c410ff1615EDDIF15IKGGD15ECHJE15FLHHI15AIIFH15CDJHI101016101010111c101010101010010|96.00%;96.00%;96.00%,96.00%,96.00%|5000|4757373#00101010|0#10';

    public function test_the_init_frame_is_read_like_the_client_reads_it(): void
    {
        $tail = (new AmaticWildcatInit)->tail(self::INIT);

        $this->assertSame([1, 2, 3, 4, 5, 10, 15, 20, 25, 30, 40, 45, 50], $tail['ladder']);
        $this->assertSame(20, $tail['coins']);
        $this->assertSame(10, $tail['lines']);
        $this->assertSame([100, 200, 300], $tail['buy']);
        $this->assertEquals([25], $tail['hunt']);

        $patched = (new AmaticWildcatInit)->withBalance(self::INIT, 123456);
        $this->assertStringContainsString('51e240100021433e8', $patched);   // 0x1e240 = 123456
        $this->assertSame($tail['ladder'], (new AmaticWildcatInit)->tail($patched)['ladder']);
    }

    public function test_wilds_count_toward_every_symbol_and_leave_with_the_win(): void
    {
        [$game] = $this->game();
        $cfg = new GameConfig($game->template, $game);
        // 6 × symbol 7 + 2 wilds = 8 → tier 0; symbol 8 only 6 + 2 = 8 too
        $grid = [
            [7, 7, 8, 8, 0], [7, 7, 8, 8, 1], [7, 7, 8, 8, 2],
            [10, 3, 4, 5, 6], [10, 3, 4, 5, 6], [0, 1, 2, 3, 4],
        ];
        $wins = app(CascadeEngine::class)->evaluate($cfg, $cfg->cascadeConfig(), $grid, 1.0);
        $by = array_column($wins, null, 'symbol');

        $this->assertSame(8, $by[7]['count']);
        $this->assertEqualsWithDelta(8, $by[7]['amount'], 0.0001);
        $this->assertSame(8, $by[8]['count']);
        $this->assertContains([3, 0], $by[7]['cells']);
    }

    public function test_a_round_is_paid_once_and_streamed_one_tumble_per_command(): void
    {
        [$game, $player] = $this->game();
        $server = app(SocketServer::class);
        $token = $this->token($game, $player);
        $server->handle(1, "A/u25,,{$token},AnubisGold,2_0_0,,,2|15|0|en-US |1280|720|1");

        for ($round = 0; $round < 40; $round++) {
            $before = (float) $player->wallet->fresh()->balance;
            $frame = $server->handle(1, 'A/u251,10,0,1,-1;-1,-1,0,0,-1,15,1,1,0,0,1')[0];
            $after = (float) $player->wallet->fresh()->balance;
            $frames = [$frame];
            while (in_array($this->state($frame), [30, 31], true)) {
                $frame = $server->handle(1, 'A/u2531,10,0,1')[0];
                $frames[] = $frame;
            }
            $last = $this->decode(end($frames));
            $this->assertContains($last['state'], [5, 9, 33]);
            // money moved once, on the spin; the last board shows the settled balance
            $this->assertEqualsWithDelta($after * 100, $last['balance'], 0.5);
            $this->assertEqualsWithDelta(($after - $before + 0.2) * 100, $last['win'], 0.5);
            if (count($frames) > 1) {
                $this->assertSame(30, $this->state($frames[0]));
                $this->assertEqualsWithDelta(($before - 0.2) * 100, $this->decode($frames[0])['balance'], 0.5);
            }
            if ($last['state'] === 5) {
                break;
            }
        }
    }

    public function test_buying_the_bonus_grants_its_spins_and_plays_them_out(): void
    {
        [$game, $player] = $this->game();
        $server = app(SocketServer::class);
        $token = $this->token($game, $player);
        $server->handle(1, "A/u25,,{$token},AnubisGold,2_0_0,,,2|15|0|en-US |1280|720|1");

        $frame = $server->handle(1, 'A/u2566,10,0,1,1')[0];
        $this->assertSame(66, $this->state($frame));
        $this->assertEqualsWithDelta(1000 - 40, (float) $player->wallet->fresh()->balance, 0.001);   // 200 × 0.20
        $this->assertSame(15, $this->decode($frame)['free_left']);

        $states = [];
        for ($k = 0; $k < 400; $k++) {
            $state = $this->state($frame);
            $states[] = $state;
            if (in_array($state, [12, 74], true)) {
                break;
            }
            $frame = $server->handle(1, in_array($state, [34, 35], true) ? 'A/u2535,10,0,1' : 'A/u256,10,0,1')[0];
        }
        $this->assertContains(end($states), [12, 74]);
        $this->assertSame(0, $this->decode($frame)['free_left']);
        // back in the base game
        $this->assertContains($this->state($server->handle(1, 'A/u251,10,0,1,-1')[0]), [5, 9, 30]);
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array{0: Game, 1: User} */
    private function game(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create([
            'name' => 'Wcg', 'slug' => 'wcg', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 94, 'max_win_multiplier' => 5000, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $strip = [0, 4, 7, 8, 1, 5, 7, 8, 2, 6, 8, 7, 3, 4, 9, 5, 6, 7, 8, 10, 4, 5, 6, 7, 8, 11, 8, 7, 6, 5];
        $tpl = GameTemplate::create([
            'code' => 'AnubisGold', 'title' => 'Anubis Gold', 'engine' => 'internal', 'client_protocol' => ClientProtocol::Amatic,
            'reel_count' => 6, 'row_count' => 5, 'symbol_count' => 12, 'symbols' => range(0, 11),
            'wild_symbol' => 10, 'scatter_symbol' => 9, 'min_match' => 3, 'has_free_spins' => true, 'has_gamble' => false,
            'free_spins_count' => 10, 'free_spins_multiplier' => 1, 'volatility' => 'medium', 'paylines' => [],
            'paytable' => [
                [200, 500, 1000], [50, 200, 500], [40, 100, 300], [30, 40, 240], [20, 30, 200], [16, 24, 160],
                [10, 20, 100], [8, 18, 80], [5, 15, 40], [0, 0, 0], [0, 0, 0], [0, 0, 0],
            ],
            'reel_strips' => array_merge(
                array_combine(array_map(fn ($i) => "reelStrip{$i}", range(1, 6)), array_fill(0, 6, $strip)),
                array_combine(array_map(fn ($i) => "reelStripBonus{$i}", range(1, 6)), array_fill(0, 6, $strip)),
            ),
            'tumble_config' => [
                'lines' => 20, 'tiers' => [8, 10, 12], 'trigger' => 4, 'retrigger' => 4, 'retrigger_spins' => 10, 'free_spins' => 10,
                'wild_symbol' => 10, 'multiplier_symbol' => 11, 'multiplier_values' => [2, 2, 3, 5],
                'multiplier_in_base' => true, 'multiplier_accumulate' => true, 'scatter_pays' => [],
            ],
            'bonus_config' => ['table' => [
                'type' => 'wildcat', 'mechanic' => 'tumble', 'init' => self::INIT,
                'free_spins' => [4 => 10, 5 => 15, 6 => 20], 'buy' => [100, 200, 300],
            ]],
        ]);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.2, 0.4], 'rtp_percent' => 94, 'max_win_multiplier' => 5000,
        ]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 1000]);

        return [$game, $player];
    }

    private function token(Game $game, User $player): string
    {
        return GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id, 'token' => 'wcg-'.uniqid(), 'is_active' => true,
        ])->token;
    }

    private function state(string $frame): int
    {
        return (int) hexdec(substr($frame, 3, 2));
    }

    /** @return array{state: int, balance: int, win: int, free_left: int} */
    private function decode(string $f): array
    {
        $i = 6;                                                 // '-11' state(2) message(1)
        $num = function () use ($f, &$i): int {
            $n = (int) hexdec($f[$i++]);
            $v = substr($f, $i, $n);
            $i += $n;

            return $n ? (int) hexdec($v) : 0;
        };
        $num();                                                 // round id
        $balance = $num();
        $win = $num();
        $reels = (int) hexdec(substr($f, $i, 2));
        $i += 2;
        for ($k = 0; $k < $reels; $k++) {
            $num();
        }
        $i += 4;                                                // bet index, lines
        $num();                                                 // bonus spins total
        $left = $num();

        return ['state' => $this->state($f), 'balance' => $balance, 'win' => $win, 'free_left' => $left];
    }
}
