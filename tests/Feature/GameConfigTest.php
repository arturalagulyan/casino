<?php

namespace Tests\Feature;

use App\Enums\Volatility;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\GameTemplates\Pages\CreateGameTemplate;
use App\Filament\Resources\GameTemplates\Pages\EditGameTemplate;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\GameConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GameConfigTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    public function test_game_config_merges_template_defaults_with_game_overrides(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $tpl = GameTemplate::create([
            'code' => 'X', 'title' => 'X',
            'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 9,
            'wild_symbol' => 8, 'wild_multiplier' => 2, 'free_spins_count' => 10,
            'gamble_win_chance' => 4, 'volatility' => 'high',
            'default_bet_options' => [10, 20, 50],
            'paytable' => [0 => [0, 0, 5, 10, 25, 0]],
        ]);
        $shop = Shop::create(['name' => 'S', 'slug' => 's', 'frontend' => 'default', 'currency' => 'EUR', 'rtp_percent' => 92]);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true,
            'wild_multiplier' => 5,                 // override
            'free_spins_count' => 20,               // override
            'reserve_percent' => 7,                 // legacy rezerv → gamble chance
            'bet_options' => [100, 200],            // override
        ]);

        $cfg = new GameConfig($tpl, $game);

        $this->assertSame(5, $cfg->wildMultiplier());          // game wins
        $this->assertSame(20, $cfg->freeSpinsCount());         // game wins
        $this->assertSame(7, $cfg->gambleWinChance());         // game rezerv wins
        $this->assertSame([100.0, 200.0], $cfg->betOptions()); // game wins
        $this->assertSame(Volatility::High, $cfg->volatility()); // from template
        $this->assertSame(8, $cfg->wildSymbol());               // from template

        // Win-chance table falls back to a volatility-shaped default.
        $this->assertGreaterThanOrEqual(3, $cfg->winChance('spin', 10, 92));
    }

    public function test_free_spins_table_symbols_and_tuning_are_db_driven(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $tpl = GameTemplate::create([
            'code' => 'FSt', 'title' => 'FSt', 'device' => 'both', 'bank_type' => 'slots',
            'default_denomination' => 1, 'symbol_count' => 9, 'wild_symbol' => 8, 'scatter_symbol' => 7,
            'has_free_spins' => true, 'free_spins_count' => 10,
            'free_spins_table' => [0, 0, 0, 12, 20, 30],   // by scatter count
            'symbols' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
            'rtp_control_window' => 120,
            'win_distribution' => ['tail_scale' => 6, 'budget_frac' => 0.7],
            'rtp_control' => ['cold_spin_chance' => 15, 'clamp_spins' => [10, 20]],
            'volatility' => 'medium',
        ]);
        $shop = Shop::create(['name' => 'S3', 'slug' => 's3', 'frontend' => 'default', 'currency' => 'EUR']);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1]);

        $cfg = new GameConfig($tpl, $game);

        $this->assertSame(12, $cfg->freeSpinsFor(3));    // 3 scatters
        $this->assertSame(30, $cfg->freeSpinsFor(5));
        $this->assertSame(30, $cfg->freeSpinsFor(9));    // clamped to last
        $this->assertSame(10, $cfg->freeSpinsFor(2));    // 0 in table → fixed grant

        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8], $cfg->symbols());
        $this->assertSame(120, $cfg->rtpControlWindow());

        // win_distribution: template override merged onto volatility defaults
        $dist = $cfg->winDistribution();
        $this->assertEquals(6, $dist['tail_scale']);
        $this->assertEquals(0.7, $dist['budget_frac']);
        $this->assertEquals(0.7, $dist['small_prob']);   // untouched medium default

        $ctrl = $cfg->rtpControl();
        $this->assertSame(15, $ctrl['cold_spin_chance']);
        $this->assertSame([10, 20], $ctrl['clamp_spins']);
        $this->assertSame(5000, $ctrl['cold_bonus_chance']); // default kept
    }

    public function test_admin_can_create_a_template_with_engine_config(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateGameTemplate::class)
            ->fillForm([
                'code' => 'SweetBonanza', 'title' => 'Sweet Bonanza',
                'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
                'reel_count' => 6, 'row_count' => 5, 'symbol_count' => 10,
                'wild_symbol' => 9, 'volatility' => 'high', 'has_free_spins' => true,
                'free_spins_count' => 12, 'default_denomination' => 1,
                'paytable' => json_encode([0 => [0, 0, 0, 5, 20, 50]]),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tpl = GameTemplate::where('code', 'SweetBonanza')->first();
        $this->assertSame(6, $tpl->reel_count);
        $this->assertSame([0 => [0, 0, 0, 5, 20, 50]], $tpl->paytable);
        $this->assertTrue($tpl->has_free_spins);
    }

    public function test_edit_pages_render(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $tpl = GameTemplate::create([
            'code' => 'Y', 'title' => 'Y', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
        ]);
        $shop = Shop::create(['name' => 'S2', 'slug' => 's2', 'frontend' => 'default', 'currency' => 'EUR']);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1]);

        Livewire::test(EditGameTemplate::class, ['record' => $tpl->getKey()])->assertOk();
        Livewire::test(EditGame::class, ['record' => $game->getKey()])->assertOk();
    }
}
