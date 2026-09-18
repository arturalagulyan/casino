<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Jackpot;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\RtpSimulationReport;
use App\Services\GamePlay\RtpSimulator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtpSimulationReportTest extends TestCase
{
    use RefreshDatabase;

    private function game(): Game
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Rtp', 'slug' => 'rtp', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 90, 'player_limit' => 100000,
            'max_win_multiplier' => 500,
        ]);

        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 50000]);

        Jackpot::create([
            'shop_id' => $shop->id, 'name' => 'Shop JP', 'currency' => 'EUR',
            'balance' => 0, 'contribution_percent' => 2, 'is_active' => true,
        ]);

        $tpl = GameTemplate::create([
            'code' => 'RtpCsv', 'title' => 'Rtp Csv', 'engine' => 'internal',
            'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
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

        return Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [10, 20, 50],
        ]);
    }

    public function test_simulator_rows_split_every_staked_spin_into_bank_jackpot_and_profit(): void
    {
        $game = $this->game()->fresh(['shop', 'template', 'jackpot']);

        $result = app(RtpSimulator::class)->run($game, 200, 10.0, 5);

        $this->assertCount(200, $result['rows']);

        foreach ($result['rows'] as $row) {
            // A staked spin's split always accounts for the whole stake; a free
            // spin (bet 0, riding the trigger spin's stake) carries no split.
            $this->assertEqualsWithDelta(
                $row['bet'],
                $row['game_in'] + $row['jackpot_in'] + $row['profit'],
                0.001,
            );

            if ($row['bet'] <= 0) {
                $this->assertSame(0.0, $row['game_in']);
                $this->assertSame(0.0, $row['jackpot_in']);
                $this->assertSame(0.0, $row['profit']);
            }
        }

        $this->assertEqualsWithDelta(
            $result['total_in'],
            $result['total_game_in'] + $result['total_jackpot_in'] + $result['total_profit'],
            0.01,
        );

        // 90% shop RTP, 2% jackpot contribution → 8% of every staked spin is profit.
        $staked = array_sum(array_column($result['rows'], 'bet'));
        $this->assertEqualsWithDelta($staked * 0.02, $result['total_jackpot_in'], 0.5);
        $this->assertEqualsWithDelta($staked * 0.08, $result['total_profit'], 0.5);

        // Real money and pools are never touched — same guarantee as before this change.
        $this->assertSame(0, \App\Models\GameRound::count());
        $this->assertSame(50000.0, (float) GameBank::first()->slots);
    }

    public function test_report_store_find_and_csv_roundtrip(): void
    {
        $game = $this->game()->fresh(['shop', 'template', 'jackpot']);
        $result = app(RtpSimulator::class)->run($game, 20, 10.0, 5);

        $store = app(RtpSimulationReport::class);
        $token = $store->store($result);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $token);

        $found = $store->find($token);
        $this->assertNotNull($found);
        $this->assertSame($result['spins'], $found['spins']);
        $this->assertCount(20, $found['rows']);

        $this->assertNull($store->find('not-a-token'));
        $this->assertNull($store->find('00000000-0000-0000-0000-000000000000'));

        $csv = $store->toCsv($found);
        $lines = preg_split('/\r\n|\n/', trim($csv));
        $this->assertCount(21, $lines); // header + 20 rows
        $this->assertStringContainsString('Game in', $lines[0]);
        $this->assertStringContainsString('Jackpot in', $lines[0]);
        $this->assertStringContainsString('Profit', $lines[0]);
    }

    public function test_report_routes_require_permission_and_a_valid_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $game = $this->game()->fresh(['shop', 'template', 'jackpot']);
        $result = app(RtpSimulator::class)->run($game, 10, 10.0, 5);
        $token = app(RtpSimulationReport::class)->store($result);

        // Guest is redirected to login.
        $this->get(route('admin.rtp-simulations.show', $token))->assertRedirect(route('filament.admin.auth.login'));

        // Logged in without games.manage permission → 403.
        $player = User::factory()->create();
        $player->assignRole('user');
        $this->actingAs($player)
            ->get(route('admin.rtp-simulations.show', $token))
            ->assertForbidden();

        // Admin can view and download.
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.rtp-simulations.show', $token))
            ->assertOk()
            ->assertSee('Rtp Csv')
            ->assertSee('Download full CSV');

        $download = $this->actingAs($admin)->get(route('admin.rtp-simulations.download', $token));
        $download->assertOk();
        $download->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Unknown token → 404.
        $this->actingAs($admin)
            ->get(route('admin.rtp-simulations.show', '11111111-1111-1111-1111-111111111111'))
            ->assertNotFound();
    }
}
