<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\GamePlay\Engine\CascadeEngine;
use App\Services\GamePlay\Engine\CascadeSlotServer;
use App\Services\GamePlay\Engine\LineSlotServer;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The first-party RoyalSpin games: installer, engine selection, cascade math. */
class RoyalSpinGamesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('game_bundles');
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->shop = Shop::create([
            'name' => 'RS Shop', 'slug' => 'rs-shop', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 92, 'player_limit' => 100000,
            'max_win_multiplier' => 500,
        ]);
        GameBank::create(['shop_id' => $this->shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $this->artisan('royalspin:install')->assertSuccessful();
    }

    private function game(string $code): Game
    {
        return Game::whereHas('template', fn ($q) => $q->where('code', $code))->where('shop_id', $this->shop->id)->firstOrFail();
    }

    private function player(): User
    {
        $player = User::factory()->create(['shop_id' => $this->shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 1000]);

        return $player;
    }

    public function test_installer_registers_templates_bundles_and_games(): void
    {
        $category = Category::where('slug', 'royalspin')->firstOrFail();

        foreach (['RoyalSevensRS', 'CrownJewelsRS', 'PharaohsRichesRS', 'CandyRoyaleRS'] as $code) {
            $template = GameTemplate::where('code', $code)->firstOrFail();
            $bundle = $template->activeBundle;

            $this->assertNotNull($bundle, "{$code} has no bundle");
            $this->assertSame('index.html', $bundle->entry);
            $html = $bundle->disk()->get($bundle->filePath('index.html'));
            $this->assertStringNotContainsString('{{BUILD}}', $html);
            $this->assertNotNull($bundle->filePath('js/rs-engine.js'));
            $this->assertNotNull($bundle->filePath('game.json'));
            $this->assertNull($bundle->filePath('math.json'), 'server math must not ship to the client');
            $this->assertTrue($this->game($code)->categories->contains($category));
            Storage::disk('public')->assertExists("game-posters/{$code}.svg");
        }

        // idempotent: an unchanged build is not re-uploaded
        $this->artisan('royalspin:install')->assertSuccessful();
        $this->assertSame(1, GameTemplate::where('code', 'CandyRoyaleRS')->first()->bundles()->count());
    }

    public function test_registry_picks_the_engine_by_mechanic(): void
    {
        $registry = app(GameRegistry::class);

        $this->assertInstanceOf(CascadeSlotServer::class, $registry->for($this->game('CandyRoyaleRS')));
        $this->assertInstanceOf(LineSlotServer::class, $registry->for($this->game('CrownJewelsRS')));
        $this->assertFalse($registry->isNative($this->game('CandyRoyaleRS')->template));
    }

    public function test_cascade_rounds_are_self_consistent_and_settle_the_wallet(): void
    {
        $game = $this->game('CandyRoyaleRS');
        $player = $this->player();
        $ctx = new GameContext($player, $game, app(Ledger::class), app(Banker::class));
        $server = app(GameRegistry::class)->for($game);

        $init = $server->handle($ctx, ['command' => 'init']);
        $this->assertSame(20, $init['config']['cascade']['lines']);

        $bet = $won = 0.0;
        for ($i = 0; $i < 60; $i++) {
            $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 5]);
            $bet += $out['bet'];
            $won += $out['win'];

            $steps = $out['cascade'];
            $this->assertNotEmpty($steps);
            $this->assertSame($out['reels'], end($steps)['grid'], 'last step is the final board');
            $this->assertSame([], end($steps)['wins'], 'a round ends on a non-paying board');
            foreach ($steps as $step) {
                $this->assertCount(6, $step['grid']);
                $this->assertEqualsWithDelta(array_sum(array_column($step['wins'], 'amount')), $step['win'], 0.0001);
            }
            $expected = $out['cascade_win'] * max(1, $out['multiplier']) + $out['scatter_win'];
            $this->assertLessThanOrEqual(round($expected, 4) + 0.0001, $out['win']);
        }

        $this->assertEqualsWithDelta(1000 - $bet + $won, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_free_spins_are_not_debited_and_count_down(): void
    {
        $game = $this->game('CandyRoyaleRS');
        $player = $this->player();
        $ctx = new GameContext($player, $game, app(Ledger::class), app(Banker::class));
        $ctx->statePut(['free_spins_left' => 2, 'free_spins_betline' => 5]);
        $server = app(GameRegistry::class)->for($game);

        $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 5]);

        $this->assertSame(0.0, $out['bet']);
        $this->assertSame('freespin', $out['state']);
        $this->assertGreaterThanOrEqual(1, $out['free_spins_left']);
        $this->assertEqualsWithDelta(1000 + $out['win'], (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_scatter_pays_counts_symbols_anywhere(): void
    {
        $game = $this->game('CandyRoyaleRS');
        $cfg = (new GameContext($this->player(), $game, app(Ledger::class), app(Banker::class)))->config();
        $engine = app(CascadeEngine::class);

        // 8 × symbol 7 (tier 0 = 100 × unit), everything else scattered below the threshold
        $grid = [[7, 7, 0, 1, 2], [7, 7, 3, 4, 5], [7, 7, 6, 0, 1], [7, 7, 2, 3, 4], [5, 6, 0, 1, 2], [3, 4, 5, 6, 0]];
        $wins = $engine->evaluate($cfg, $cfg->cascadeConfig(), $grid, 0.01);

        $this->assertCount(1, $wins);
        $this->assertSame(7, $wins[0]['symbol']);
        $this->assertSame(8, $wins[0]['count']);
        $this->assertEqualsWithDelta(1.0, $wins[0]['amount'], 0.0001);
    }

    public function test_line_game_plays_through_the_standard_endpoint(): void
    {
        $game = $this->game('PharaohsRichesRS');
        $player = $this->player();
        $session = $player->gameSessions()->create(['game_id' => $game->id, 'token' => 'rs-test-token', 'is_active' => true, 'last_seen_at' => now()]);

        $init = $this->postJson('/api/game/PharaohsRichesRS/server', ['session' => $session->token, 'command' => 'init'])->assertOk()->json();
        $this->assertCount(20, $init['config']['paylines']);

        $out = $this->postJson('/api/game/PharaohsRichesRS/server', ['session' => $session->token, 'command' => 'bet', 'bet' => 1, 'lines' => 20])->assertOk()->json();
        $this->assertEqualsWithDelta(0.20, $out['bet'], 0.0001);
        $this->assertCount(5, $out['reels']);
    }
}
