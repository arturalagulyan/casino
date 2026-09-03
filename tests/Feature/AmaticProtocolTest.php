<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\SocketServer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The legacy Amatic "amarent" WebSocket protocol — `{"gameData":"A/uNNN,…"}` in,
 * a packed hex string out. One generic handler; the template holds the maths.
 */
class AmaticProtocolTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: Game, 2: User} */
    private function amaticGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Amatic', 'slug' => 'amatic', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 94, 'max_win_multiplier' => 500, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $tpl = GameTemplate::create([
            'code' => 'TestAmaticSlot', 'title' => 'Test Amatic Slot',
            'engine' => 'internal', 'client_protocol' => ClientProtocol::Amatic,
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 10,
            'symbols' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
            'wild_symbol' => 0, 'scatter_symbol' => 9,
            'min_match' => 2, 'has_free_spins' => true, 'has_gamble' => true,
            'gamble_win_chance' => 2, 'free_spins_count' => 10, 'free_spins_multiplier' => 3,
            'volatility' => 'medium',
            'paytable' => [
                0 => [0, 0, 20, 200, 1000, 5000],
                1 => [0, 0, 5, 40, 200, 1000],
                2 => [0, 0, 5, 40, 200, 1000],
                3 => [0, 0, 0, 20, 80, 400],
                4 => [0, 0, 0, 20, 80, 400],
                5 => [0, 0, 0, 10, 40, 150],
                6 => [0, 0, 0, 10, 40, 150],
                7 => [0, 0, 0, 5, 20, 75],
                8 => [0, 0, 0, 5, 20, 75],
                9 => [0, 0, 2, 5, 20, 500],
            ],
            'reel_strips' => [
                'reelStrip1' => [1, 3, 5, 2, 4, 6, 0, 7, 9, 3, 5, 2, 6, 4, 8, 3, 5, 1, 4, 6],
                'reelStrip2' => [2, 4, 6, 1, 3, 5, 0, 7, 9, 4, 6, 1, 5, 3, 8, 4, 6, 2, 3, 5],
                'reelStrip3' => [3, 5, 1, 4, 6, 2, 0, 7, 9, 5, 1, 4, 6, 2, 8, 5, 1, 3, 6, 2],
                'reelStrip4' => [4, 6, 2, 5, 1, 3, 0, 7, 9, 6, 2, 5, 1, 3, 8, 6, 2, 4, 1, 3],
                'reelStrip5' => [5, 1, 3, 6, 2, 4, 0, 7, 9, 1, 3, 6, 2, 4, 8, 1, 3, 5, 2, 4],
            ],
            'paylines' => array_fill(0, 10, [1, 1, 1, 1, 1]),
        ]);

        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [1, 2, 5, 10, 20],
            'rtp_percent' => 94, 'max_win_multiplier' => 500,
        ]);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 10000]);

        return [$shop, $game, $player];
    }

    private function openSession(User $player, Game $game): GameSession
    {
        return GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id,
            'token' => 'am-'.uniqid(), 'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function send(GameSession $session, string $gameData): array
    {
        return app(SocketServer::class)->handle(':::'.json_encode([
            'gameData' => $gameData,
            'sessionId' => $session->token,
            'gameName' => $session->game->template->code,
        ]));
    }

    private function walletDelta(User $player): float
    {
        return $player->transactions()->get()->sum(
            fn ($t) => ($t->direction->value === 'debit' ? -1 : 1) * (float) $t->amount,
        );
    }

    public function test_bad_session_gets_a_json_error(): void
    {
        $this->amaticGame();

        $out = app(SocketServer::class)->handle(':::'.json_encode([
            'gameData' => 'A/u25', 'sessionId' => 'nope', 'gameName' => 'TestAmaticSlot',
        ]));

        $this->assertStringContainsString('invalid login', $out[0]);
    }

    public function test_settings_packet_is_a_hex_string_carrying_the_reel_strips(): void
    {
        [, $game, $player] = $this->amaticGame();
        $session = $this->openSession($player, $game);

        $out = $this->send($session, 'A/u25');

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('05', $out[0]);            // legacy A/u25 header
        $this->assertStringNotContainsString('responseEvent', $out[0]);   // not JSON
        // the balance (10000 * 100 cents = 0xF4240) is length-prefixed hex somewhere in it
        $this->assertStringContainsString('5f4240', strtolower($out[0]));
    }

    public function test_a_spin_debits_the_wallet_and_conserves_money(): void
    {
        [, $game, $player] = $this->amaticGame();
        $session = $this->openSession($player, $game);

        // A/u251,<lines>,<betIndex>  → 10 lines, bet index 1 (=2 credits) → stake 20
        $out = $this->send($session, 'A/u251,10,1');

        $this->assertCount(1, $out);
        $this->assertMatchesRegularExpression('/^1[0-9a-f]{2}010/', $out[0]);   // spin header
        [$hex, $json] = explode('_', $out[0], 2);
        $reels = json_decode($json, true);
        $this->assertArrayHasKey('reel1', $reels);
        $this->assertCount(3, $reels['reel1']);
        $this->assertCount(5, $reels['rp']);

        $round = $player->rounds()->latest('id')->first();
        $this->assertNotNull($round);
        $this->assertSame('20.0000', $round->bet);

        $this->assertEqualsWithDelta(
            10000 + $this->walletDelta($player),
            (float) $player->wallet->fresh()->balance,
            0.001,
        );
    }

    public function test_bet_beyond_balance_is_refused_without_debiting(): void
    {
        [, $game, $player] = $this->amaticGame();
        $session = $this->openSession($player, $game);
        $player->wallet->update(['balance' => 30]);            // < 200 stake (20 credits * 10 lines)

        $out = $this->send($session, 'A/u251,10,4');           // betIndex 4 = 20 credits

        $this->assertStringContainsString('invalid balance', $out[0]);
        $this->assertSame(30.0, (float) $player->wallet->fresh()->balance);
        $this->assertSame(0, $player->rounds()->count());
    }

    public function test_balance_poll_returns_the_update_frame(): void
    {
        [, $game, $player] = $this->amaticGame();
        $session = $this->openSession($player, $game);

        $out = $this->send($session, 'A/u350,0');

        $this->assertStringStartsWith('UPDATE#', $out[0]);
        $this->assertSame('UPDATE#1000000', $out[0]);          // 10000 * 100
    }

    public function test_free_spins_are_free_and_run_down(): void
    {
        [, $game, $player] = $this->amaticGame();
        $session = $this->openSession($player, $game);

        $session->update(['state' => ['features' => [
            'last_bet' => 2.0, 'last_lines' => 10, 'last_bet_index' => 1,
            'frozen_balance' => 9980.0, 'bonus_win' => 0.0, 'total_win' => 0.0,
            'free_total' => 10, 'free_left' => 10,
        ]]]);
        $player->wallet->update(['balance' => 9980]);
        $before = (float) $player->wallet->fresh()->balance;

        $out = $this->send($session, 'A/u256');

        $this->assertMatchesRegularExpression('/^1[0-9a-f]{2}010/', $out[0]);
        $this->assertSame(9, $session->fresh()->state['features']['free_left']);
        $this->assertGreaterThanOrEqual($before, (float) $player->wallet->fresh()->balance);
        $this->assertSame('0.0000', $player->rounds()->latest('id')->first()->bet);
    }

    public function test_gamble_is_wallet_consistent(): void
    {
        [, $game, $player] = $this->amaticGame();
        $session = $this->openSession($player, $game);
        $player->wallet->update(['balance' => 100000]);

        $won = false;
        for ($i = 0; $i < 80; $i++) {
            $out = $this->send($session, 'A/u251,10,2');
            [$hex] = explode('_', $out[0], 2);
            // step-win is HexFormat(win*100) right after the 6-char header + balance field…
            // simplest: check the round's win
            if ((float) $player->rounds()->latest('id')->first()->win > 0) {
                $won = true;
                break;
            }
        }

        if ($won) {
            $out = $this->send($session, 'A/u257,1');   // gamble red
            $this->assertMatchesRegularExpression('/^10[78]010/', $out[0]);
        }

        $this->assertSame(0, $player->rounds()->where('win', '<', 0)->count());
        $this->assertEqualsWithDelta(
            100000 + $this->walletDelta($player),
            (float) $player->wallet->fresh()->balance,
            0.01,
        );
    }
}
