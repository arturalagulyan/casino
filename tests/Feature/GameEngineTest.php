<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\GamePlay\Engine\LineSlotServer;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use App\Services\SeamlessWallet\GameLaunch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameEngineTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $shopAttrs = [], array $gameAttrs = []): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create($shopAttrs + [
            'name' => 'Eng', 'slug' => 'eng', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 90, 'player_limit' => 100000,
            'max_win_multiplier' => 500,
        ]);

        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 50000]);

        $tpl = GameTemplate::create([
            'code' => 'ActionMoney', 'title' => 'Action Money',
            'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 9,
            'wild_symbol' => 8, 'scatter_symbol' => 7, 'wild_multiplier' => 2,
            'has_bonus' => true, 'has_free_spins' => true, 'free_spins_count' => 8,
            'volatility' => 'medium',
            'paytable' => [
                0 => [0, 0, 5, 10, 25, 0], 1 => [0, 0, 5, 10, 25, 0], 2 => [0, 0, 5, 15, 40, 0],
                3 => [0, 0, 10, 20, 60, 0], 4 => [0, 0, 15, 40, 100, 0], 5 => [0, 0, 20, 60, 150, 0],
                6 => [0, 0, 25, 100, 250, 0], 7 => [0, 0, 2, 5, 20, 0], 8 => [0, 0, 0, 0, 0, 0],
            ],
        ]);
        $game = Game::create($gameAttrs + [
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [10, 20, 50],
        ]);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 10000]);

        return [$shop, $game, $player];
    }

    private function context(User $player, Game $game): GameContext
    {
        return new GameContext($player, $game, app(Ledger::class), app(Banker::class));
    }

    public function test_registry_falls_back_to_line_slot_engine(): void
    {
        [, $game] = $this->game();
        $this->assertInstanceOf(LineSlotServer::class, app(GameRegistry::class)->for($game->fresh()));
    }

    public function test_init_returns_config_and_balance(): void
    {
        [, $game, $player] = $this->game();

        $out = app(GameRegistry::class)->for($game)->handle($this->context($player, $game), ['command' => 'init']);

        $this->assertSame('init', $out['command']);
        $this->assertSame(5, $out['config']['reels']);
        $this->assertCount(10, $out['config']['paylines']);
        $this->assertEquals([10, 20, 50], $out['bet_options']);
        $this->assertEqualsWithDelta(10000.0, $out['balance'], 0.001);
    }

    public function test_single_spin_moves_money_and_records_a_round(): void
    {
        [$shop, $game, $player] = $this->game();
        $ctx = $this->context($player, $game);
        $server = app(GameRegistry::class)->for($game);

        $bankBefore = (float) $shop->bank('EUR')->slots;

        $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);

        $this->assertSame('bet', $out['command']);
        $this->assertSame(100.0, $out['bet']);                       // 10 betline × 10 lines
        $this->assertGreaterThanOrEqual(0, $out['win']);

        $round = $player->rounds()->latest('id')->first();
        $this->assertNotNull($round);
        $this->assertSame('100.0000', $round->bet);
        $this->assertEqualsWithDelta($out['win'], (float) $round->win, 0.001);

        // wallet: -100 bet +win
        $this->assertEqualsWithDelta(10000 - 100 + $out['win'], (float) $player->wallet->fresh()->balance, 0.001);

        // bank fed ~90% of stake (minus jackpot slice), minus any win paid
        $bankAfter = (float) $shop->bank('EUR')->fresh()->slots;
        $this->assertEqualsWithDelta($bankBefore + 90 - $out['win'], $bankAfter, 0.5);

        $this->assertDatabaseHas('transactions', ['source' => 'bet', 'user_id' => $player->id]);
        $this->assertDatabaseCount('game_logs', 1);
    }

    public function test_rtp_correction_pulls_a_hot_game_back_toward_target(): void
    {
        // rtp_control kicks in after this many rounds; keep it small so the test
        // exercises the correction without running for thousands of spins.
        [, $game, $player] = $this->game([], [
            'rtp_percent' => 90,
        ]);
        $game->template->update(['rtp_control_window' => 60]);
        $ctx = $this->context($player, $game);
        $server = app(GameRegistry::class)->for($game);

        $player->wallet->update(['balance' => 50_000_000]);

        $windowRtp = function (int $n) use ($server, $ctx): float {
            $bet = $win = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);
                $bet += 100;
                $win += $out['win'];
            }

            return $win / $bet * 100;
        };

        $early = $windowRtp(120);   // before + as the window elapses
        $late = $windowRtp(600);    // correction now active

        // The faithful rejection engine can't retroactively un-pay a hot start,
        // but the loop must bend the cumulative payout rate down toward target.
        $this->assertLessThan($early, $late, "correction did not bite: {$early}% → {$late}%");
        $this->assertLessThan(120, $late, "late RTP still runaway at {$late}%");
    }

    public function test_win_is_capped_by_shop_max_win(): void
    {
        // tiny bank + tiny max-win: a win can never exceed 5× the stake here
        [$shop, $game, $player] = $this->game(['max_win_multiplier' => 5]);
        $shop->bank('EUR')->update(['slots' => 30]);

        $ctx = $this->context($player, $game);
        $server = app(GameRegistry::class)->for($game);

        for ($i = 0; $i < 50; $i++) {
            $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);
            $this->assertLessThanOrEqual(100 * 5, $out['win']);
        }
    }

    public function test_http_endpoint_plays_a_round(): void
    {
        [, $game, $player] = $this->game();

        $session = GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id,
            'token' => 'sess-token-1', 'is_active' => true,
        ]);

        $this->postJson('/api/game/ActionMoney/server', [
            'session' => 'sess-token-1', 'command' => 'init',
        ])->assertOk()->assertJsonPath('command', 'init');

        $res = $this->postJson('/api/game/ActionMoney/server', [
            'session' => 'sess-token-1', 'command' => 'bet', 'bet' => 10, 'lines' => 10,
        ])->assertOk();

        $res->assertJsonPath('bet', 100);
        $this->assertDatabaseHas('game_rounds', ['user_id' => $player->id, 'game_id' => $game->id]);

        $this->postJson('/api/game/ActionMoney/server', ['session' => 'bad', 'command' => 'init'])
            ->assertStatus(403);
    }

    public function test_demo_shell_serves_with_valid_launch_token_only(): void
    {
        [, $game, $player] = $this->game();
        $token = app(GameLaunch::class)->issueToken($player, $game);

        $this->get('/games/ActionMoney?token='.urlencode($token))
            ->assertOk()
            ->assertSee('window.CasinoGame')
            ->assertSee('/api/game/ActionMoney/server');

        $this->get('/games/ActionMoney')->assertForbidden();
    }
}
