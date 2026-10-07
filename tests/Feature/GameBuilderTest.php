<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameDesign;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\GamePlay\Engine\CascadeSlotServer;
use App\Services\GamePlay\Engine\LineSlotServer;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use App\Services\RoyalSpin\DesignPresets;
use App\Services\RoyalSpin\GameBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Admin Game Builder: designs → math, bundles, templates; Olympus cascade extras (orbs in base, total multiplier, buy, ante). */
class GameBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private GameBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('game_bundles');
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->shop = Shop::create([
            'name' => 'GB Shop', 'slug' => 'gb-shop', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 92, 'player_limit' => 100000,
            'max_win_multiplier' => 5000,
        ]);
        GameBank::create(['shop_id' => $this->shop->id, 'currency' => 'EUR', 'slots' => 1000000]);
        $this->builder = app(GameBuilder::class);
    }

    private function design(string $preset, string $code): GameDesign
    {
        return GameDesign::create(['code' => $code] + DesignPresets::get($preset));
    }

    private function context(Game $game, float $balance = 1000): GameContext
    {
        $player = User::factory()->create(['shop_id' => $this->shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => $balance]);

        return new GameContext($player, $game, app(Ledger::class), app(Banker::class));
    }

    private function publishedGame(string $preset, string $code): Game
    {
        $this->builder->publish($this->design($preset, $code));

        return Game::whereHas('template', fn ($q) => $q->where('code', $code))->where('shop_id', $this->shop->id)->firstOrFail();
    }

    public function test_every_preset_is_valid(): void
    {
        foreach (array_keys(DesignPresets::all()) as $i => $key) {
            $this->assertSame([], $this->builder->validate($this->design($key, 'Preset'.$i.'RS')), $key);
        }
    }

    public function test_publishing_builds_template_bundle_poster_and_shop_games(): void
    {
        $design = $this->design('olympus-cascade', 'ThunderTestRS');
        $out = $this->builder->publish($design);

        $template = GameTemplate::where('code', 'ThunderTestRS')->firstOrFail();
        $this->assertSame($template->id, $design->fresh()->template_id);
        $this->assertSame(1, $out['shops']);
        $this->assertSame($design->id, $template->layout['builder_design']);
        $this->assertTrue($template->tumble_config['multiplier_accumulate']);
        $this->assertSame(100.0, (float) $template->tumble_config['buy_feature']);
        // pays are entered × total bet, stored in bet units (× 20 lines)
        $this->assertEqualsWithDelta(1000, $template->paytable[8][2], 0.001);
        $this->assertContains(10, $template->reel_strips['reelStrip1'], 'orbs land in the base game');
        $this->assertInstanceOf(CascadeSlotServer::class, app(GameRegistry::class)->resolve($template));

        $bundle = $template->activeBundle;
        $meta = json_decode($bundle->disk()->get($bundle->filePath('game.json')), true);
        $this->assertSame('olympus', $meta['skin']);
        $this->assertSame('img/character.svg', $meta['assets']['character']);
        $this->assertCount(4, $meta['orbs']);
        $this->assertNotNull($bundle->filePath('img/sym/9.svg'));
        $this->assertNotNull($bundle->filePath('js/rs-engine.js'));
        $this->assertStringNotContainsString('{{BUILD}}', $bundle->disk()->get($bundle->filePath('index.html')));
        Storage::disk('public')->assertExists($template->poster_path);

        $game = Game::where('template_id', $template->id)->where('shop_id', $this->shop->id)->firstOrFail();
        $this->assertTrue($game->categories->contains(Category::where('slug', 'royalspin')->first()));

        // unchanged design → same bundle; per-shop bets survive a re-publish
        $game->update(['bet_options' => [5, 10]]);
        $again = $this->builder->publish($design->fresh());
        $this->assertStringContainsString('unchanged', $again['bundle']);
        $this->assertSame([5, 10], array_map('intval', $game->fresh()->bet_options));
    }

    public function test_line_design_generates_paylines_paytable_and_strips(): void
    {
        $design = $this->design('olympus-lines', 'RichesTestRS');
        $math = $this->builder->math($design);

        $this->assertCount(20, $math['paylines']);
        $this->assertSame([1, 1, 1, 1, 1], $math['paylines'][0]);
        $this->assertCount(6, $math['paytable'][0]);
        $this->assertSame(8, $math['wild_symbol']);
        $this->assertSame(9, $math['scatter_symbol']);
        $this->assertSame([0, 0, 0, 12, 15, 20], $math['free_spins_table']);
        $this->assertArrayHasKey('reelStripBonus5', $math['reel_strips']);
        $this->assertSame($math, $this->builder->math($design), 'strips are deterministic');

        $this->builder->publish($design);
        $this->assertInstanceOf(LineSlotServer::class, app(GameRegistry::class)->resolve(GameTemplate::where('code', 'RichesTestRS')->first()));

        // a 3x3 board: straight rows first, every line distinct
        $lines = $this->builder->allPaylines(3, 3);
        $this->assertSame([[1, 1, 1], [0, 0, 0], [2, 2, 2]], array_slice($lines, 0, 3));
        $this->assertSame(count($lines), count(array_unique(array_map('json_encode', $lines))));
    }

    public function test_validation_catches_broken_designs(): void
    {
        $design = $this->design('olympus-lines', 'BrokenRS');
        $symbols = $design->symbols;
        $symbols[0]['role'] = 'wild';            // second wild
        $symbols[1]['role'] = 'multiplier';      // orbs in a line game
        $symbols[2]['art'] = 'no-such-art';      // no picture
        $design->symbols = $symbols;
        $design->settings = ['payline_count' => 500] + $design->settings;

        $errors = implode("\n", $this->builder->validate($design));
        $this->assertStringContainsString('Only one wild', $errors);
        $this->assertStringContainsString('Multiplier orbs only work in cascade', $errors);
        $this->assertStringContainsString('pick art from the pack', $errors);
        $this->assertStringContainsString('distinct lines', $errors);

        $this->expectException(\RuntimeException::class);
        $this->builder->publish($design);
    }

    public function test_feature_buy_charges_the_price_and_always_triggers_free_spins(): void
    {
        $game = $this->publishedGame('olympus-cascade', 'BuyTestRS');
        $ctx = $this->context($game);
        $server = app(GameRegistry::class)->for($game);

        $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 1, 'buy' => true]);

        // stake = 1 coin × 0.01 × 20 lines = 0.20 → buy = 100 × 0.20
        $this->assertEqualsWithDelta(20.0, $out['bet'], 0.0001);
        $this->assertSame(15, $out['free_spins_awarded']);
        $this->assertSame(15, (int) $ctx->stateGet('free_spins_left'));
        $this->assertSame(0, (int) $ctx->stateGet('free_spins_multiplier'));
        $this->assertEqualsWithDelta(1000 - 20 + $out['win'], (float) $ctx->user->wallet->fresh()->balance, 0.001);
    }

    public function test_free_spins_keep_a_running_total_multiplier(): void
    {
        $game = $this->publishedGame('olympus-cascade', 'TotalTestRS');
        $ctx = $this->context($game);
        $ctx->statePut(['free_spins_left' => 40, 'free_spins_betline' => 1, 'free_spins_multiplier' => 0]);
        $server = app(GameRegistry::class)->for($game);

        $total = 0;
        for ($i = 0; $i < 30; $i++) {
            $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 1]);
            $this->assertArrayHasKey('total_multiplier', $out);
            $this->assertGreaterThanOrEqual($total, $out['total_multiplier'], 'the total never drops during the feature');
            if ($out['cascade_win'] > 0 && $out['multiplier'] > 0) {
                $this->assertSame($out['total_multiplier'], $out['multiplier'], 'a winning round is multiplied by the whole total');
            }
            $total = $out['total_multiplier'];
            $this->assertSame($total, (int) $ctx->stateGet('free_spins_multiplier'));
        }
    }

    public function test_ante_bet_raises_the_stake(): void
    {
        $game = $this->publishedGame('olympus-cascade', 'AnteTestRS');
        $ctx = $this->context($game);
        $server = app(GameRegistry::class)->for($game);

        $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 1, 'ante' => true]);
        $this->assertEqualsWithDelta(0.25, $out['bet'], 0.0001);

        // a cascade game without the side bets refuses them
        $design = $this->design('olympus-cascade', 'PlainTestRS');
        $design->update(['settings' => ['buy_feature' => 0, 'ante_bet' => 0] + $design->settings]);
        $this->builder->publish($design);
        $plain = Game::whereHas('template', fn ($q) => $q->where('code', 'PlainTestRS'))->firstOrFail();
        $this->expectException(\RuntimeException::class);
        app(GameRegistry::class)->for($plain)->handle($this->context($plain), ['command' => 'bet', 'bet' => 1, 'buy' => true]);
    }

    public function test_a_shipped_game_can_be_redesigned_in_place(): void
    {
        $this->artisan('royalspin:install', ['--only' => 'CandyRoyaleRS'])->assertSuccessful();

        $design = $this->builder->importShippedGame('CandyRoyaleRS');
        $this->assertSame(GameDesign::MECHANIC_CASCADE, $design->mechanic);
        $this->assertSame('candy-royale', $design->art_pack);
        $this->assertSame([], $this->builder->validate($design));
        // re-imported pays are × total bet: 100 units / 20 lines
        $this->assertEqualsWithDelta(5, $design->symbols[7]['pays'][0], 0.0001);

        // re-skin it with the Olympus look and publish over the live game
        $design->update(['skin' => 'olympus']);
        $this->builder->publish($design);
        $template = GameTemplate::where('code', 'CandyRoyaleRS')->first();
        $this->assertSame($design->id, $template->layout['builder_design']);
        $this->assertSame(2, $template->bundles()->count());

        // the installer leaves a builder-managed game alone
        $this->artisan('royalspin:install', ['--only' => 'CandyRoyaleRS'])->assertSuccessful();
        $this->assertSame($design->id, $template->fresh()->layout['builder_design']);
    }

    public function test_builder_pages_render_for_staff(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $design = $this->design('olympus-cascade', 'PageTestRS');

        $this->actingAs($admin)->get('/admin/game-designs')->assertOk()->assertSee('Game Builder');
        $this->actingAs($admin)->get("/admin/game-designs/{$design->id}/edit")->assertOk()->assertSee('PageTestRS');
        $this->actingAs($admin)->get('/admin/royalspin-packs/olympus/img/sym/crown.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->actingAs($admin)->get('/admin/royalspin-packs/olympus/../pack.json')->assertNotFound();
    }
}
