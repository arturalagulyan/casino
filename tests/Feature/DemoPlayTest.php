<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\GamePlay\DemoLauncher;
use App\Services\GamePlay\Engine\LineSlotServer;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoPlayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Shop, Game} */
    private function game(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Demo Shop', 'slug' => 'demo-shop', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 90, 'player_limit' => 100000,
            'max_win_multiplier' => 500,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 50000]);

        $tpl = GameTemplate::create([
            'code' => 'DemoGame', 'title' => 'Demo Game',
            'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 9,
            'wild_symbol' => 8, 'scatter_symbol' => 7, 'wild_multiplier' => 2,
            'volatility' => 'medium',
            'paytable' => [
                0 => [0, 0, 5, 10, 25, 0], 1 => [0, 0, 5, 10, 25, 0], 2 => [0, 0, 5, 15, 40, 0],
                3 => [0, 0, 10, 20, 60, 0], 4 => [0, 0, 15, 40, 100, 0], 5 => [0, 0, 20, 60, 150, 0],
                6 => [0, 0, 25, 100, 250, 0], 7 => [0, 0, 2, 5, 20, 0], 8 => [0, 0, 0, 0, 0, 0],
            ],
        ]);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [10, 20, 50],
        ]);

        return [$shop, $game];
    }

    public function test_demo_launcher_makes_a_free_demo_player_with_a_fresh_bankroll(): void
    {
        [$shop] = $this->game();

        $player = app(DemoLauncher::class)->player($shop);

        $this->assertTrue($player->free_demo);
        $this->assertTrue($player->hasRole('user'));
        $this->assertSame($shop->id, $player->shop_id);
    }

    public function test_demo_play_moves_only_the_demo_wallet(): void
    {
        [$shop, $game] = $this->game();
        $player = app(DemoLauncher::class)->player($shop);
        $player->wallet->update(['balance' => DemoLauncher::BANKROLL]);

        $bankBefore = (float) $shop->bank('EUR')->slots;
        $ctx = new GameContext($player->fresh(), $game, app(Ledger::class), app(Banker::class));
        $this->assertTrue($ctx->demo);

        $server = app(GameRegistry::class)->for($game);
        $win = 0.0;
        for ($i = 0; $i < 40; $i++) {
            $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);
            $win += $out['win'];
        }

        // demo wallet tracked the play …
        $this->assertEqualsWithDelta(
            DemoLauncher::BANKROLL - 40 * 100 + $win,
            (float) $player->wallet->fresh()->balance,
            0.01,
        );

        // … but nothing else did
        $this->assertSame($bankBefore, (float) $shop->bank('EUR')->fresh()->slots);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('game_rounds', 0);
        $this->assertDatabaseCount('game_logs', 0);
        $game->refresh();
        $this->assertSame('0.0000', $game->total_bet);
        $this->assertSame('0.0000', $game->total_win);
        $this->assertSame(0, $game->rounds_count);
    }

    public function test_demo_route_needs_staff_and_redirects_into_the_game(): void
    {
        [$shop, $game] = $this->game();

        // guest → login redirect
        $this->get('/games/demo/DemoGame')->assertRedirect('/admin/login');

        // plain player → forbidden
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $this->actingAs($player)->get('/games/demo/DemoGame')->assertForbidden();

        // staff → straight into the game (single shop, no picker)
        $admin = User::factory()->create(['shop_id' => $shop->id]);
        $admin->assignRole('admin');
        $res = $this->actingAs($admin)->get('/games/demo/DemoGame');
        $res->assertRedirect();
        $this->assertStringContainsString('/games/DemoGame?token=', $res->headers->get('Location'));
    }

    public function test_line_slot_server_is_used_for_the_internal_engine(): void
    {
        [, $game] = $this->game();
        $this->assertInstanceOf(LineSlotServer::class, app(GameRegistry::class)->for($game));
    }
}
