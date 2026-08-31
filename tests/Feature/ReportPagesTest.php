<?php

namespace Tests\Feature;

use App\Filament\Pages\CashReport;
use App\Filament\Pages\GameStatReport;
use App\Models\Game;
use App\Models\GameRound;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_report_aggregates_by_shop_and_currency(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $shop = Shop::create(['name' => 'Rep', 'slug' => 'rep', 'frontend' => 'default', 'currency' => 'EUR']);
        $tpl = GameTemplate::create(['code' => 'X', 'title' => 'X', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1, 'is_visible' => true]);
        $player = User::factory()->create(['shop_id' => $shop->id]);
        $player->assignRole('user');

        foreach ([['EUR', 100, 60], ['EUR', 100, 40], ['USD', 50, 90]] as [$cur, $bet, $win]) {
            GameRound::create([
                'shop_id' => $shop->id, 'user_id' => $player->id, 'game_id' => $game->id,
                'game_code' => 'X', 'currency' => $cur, 'bet' => $bet, 'win' => $win,
                'balance_after' => 0, 'played_at' => now()->subDay(),
            ]);
        }

        $page = Livewire::test(CashReport::class)->assertOk();
        $totals = $page->instance()->totals();

        $this->assertEqualsWithDelta(200.0, $totals['EUR']['in'], 0.001);
        $this->assertEqualsWithDelta(100.0, $totals['EUR']['out'], 0.001);
        $this->assertEqualsWithDelta(100.0, $totals['EUR']['net'], 0.001);
        $this->assertArrayHasKey('USD', $totals);
        $page->assertSee('Rep')->assertSee('50.00%');
    }

    public function test_game_stat_report_renders_and_filters(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(GameStatReport::class)
            ->assertOk()
            ->set('tableFilters.currency.value', 'EUR')
            ->assertOk();
    }

    public function test_cash_report_hidden_without_stats_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $player = User::factory()->create();
        $player->assignRole('user');

        $this->assertFalse(CashReport::canAccess() && $player->can('x')); // sanity
        $this->actingAs($player);
        $this->assertFalse(CashReport::canAccess());
    }
}
