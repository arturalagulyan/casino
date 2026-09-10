<?php

namespace Tests\Feature;

use App\Enums\BankType;
use App\Enums\TxnDirection;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserBank;
use App\Services\Banker;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-player win/loss manipulation via user_banks:
 *  - a manipulated player's losing stakes still feed the shared shop bank;
 *  - their WINS are paid from (and capped by) their own user bank;
 *  - a zero / negative user bank freezes their wins entirely;
 *  - Ledger::adjustUserBankPool is the audited way an admin moves that money.
 */
class PlayerManipulationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: Game, 2: User} */
    private function game(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Manip', 'slug' => 'manip', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 90, 'player_limit' => 1_000_000,
            'max_win_multiplier' => 500,
        ]);

        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 50_000]);

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

        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [10, 20, 50],
        ]);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 500_000]);

        return [$shop, $game, $player];
    }

    private function context(User $player, Game $game): GameContext
    {
        return new GameContext($player, $game, app(Ledger::class), app(Banker::class));
    }

    private function spin(GameContext $ctx, Game $game, int $times): float
    {
        $server = app(GameRegistry::class)->for($game);
        $totalWin = 0.0;

        for ($i = 0; $i < $times; $i++) {
            $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);
            $totalWin += (float) $out['win'];
        }

        return $totalWin;
    }

    public function test_manipulated_player_feeds_the_shop_bank_but_wins_drain_the_user_bank(): void
    {
        [$shop, $game, $player] = $this->game();

        UserBank::create([
            'user_id' => $player->id, 'shop_id' => $shop->id, 'currency' => 'EUR',
            'slots' => 4_000, 'is_active' => true,
        ]);

        $shopBefore = (float) $shop->bank('EUR')->slots;
        $ctx = $this->context($player, $game);

        $totalWin = $this->spin($ctx, $game, 200);

        // Every losing stake fed the shared shop pool: +90 per spin, and NOT a
        // cent of it was drained by this player's wins.
        $this->assertEqualsWithDelta($shopBefore + 90 * 200, (float) $shop->bank('EUR')->fresh()->slots, 1.0);

        // The wins came out of the user bank, pound for pound.
        $this->assertGreaterThan(0.0, $totalWin);
        $this->assertEqualsWithDelta(4_000 - $totalWin, (float) $player->bank->fresh()->slots, 1.0);

        // Rounds are tagged as settled against the user bank.
        $round = $player->rounds()->latest('id')->first();
        $this->assertSame('user_bank', $round->bank_snapshot['win_bank']);
    }

    public function test_zero_user_bank_freezes_wins_even_with_a_flush_shop_bank(): void
    {
        [$shop, $game, $player] = $this->game();
        $this->assertGreaterThan(10_000, (float) $shop->bank('EUR')->slots);

        UserBank::create([
            'user_id' => $player->id, 'shop_id' => $shop->id, 'currency' => 'EUR',
            'slots' => 0, 'is_active' => true,
        ]);

        $ctx = $this->context($player, $game);
        $server = app(GameRegistry::class)->for($game);

        for ($i = 0; $i < 40; $i++) {
            $out = $server->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);
            $this->assertSame(0.0, round((float) $out['win'], 4), "spin {$i} paid from a zeroed user bank");
        }

        // user bank untouched (wins never happened); shop bank still grew.
        $this->assertSame(0.0, (float) $player->bank->fresh()->slots);
    }

    public function test_inactive_user_bank_is_ignored_and_play_settles_against_the_shop_bank(): void
    {
        [$shop, $game, $player] = $this->game();

        UserBank::create([
            'user_id' => $player->id, 'shop_id' => $shop->id, 'currency' => 'EUR',
            'slots' => 0, 'is_active' => false,   // manipulation OFF
        ]);

        $ctx = $this->context($player, $game);
        $this->assertFalse($ctx->usingUserBank());

        $this->spin($ctx, $game, 30);

        $round = $player->rounds()->latest('id')->first();
        $this->assertSame('game_bank', $round->bank_snapshot['win_bank']);
        $this->assertSame(0.0, (float) $player->bank->fresh()->slots);   // never touched
    }

    public function test_adjust_user_bank_pool_writes_one_audited_transaction(): void
    {
        [$shop, $game, $player] = $this->game();
        $admin = User::factory()->create(['shop_id' => $shop->id]);
        $admin->assignRole('admin');

        $bank = UserBank::create([
            'user_id' => $player->id, 'shop_id' => $shop->id, 'currency' => 'EUR', 'is_active' => true,
        ]);

        $txn = app(Ledger::class)->adjustUserBankPool(
            $bank, BankType::Slots, 1_500, TxnDirection::Credit, $admin,
        );

        $this->assertEqualsWithDelta(1_500, (float) $bank->fresh()->slots, 0.001);
        $this->assertSame('user_bank', $txn->source->value);
        $this->assertSame($player->id, $txn->user_id);
        $this->assertSame($admin->id, $txn->counterparty_id);
        $this->assertSame(1_500.0, (float) $txn->amount);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_users_table_flags_players_with_manipulation_on(): void
    {
        [$shop, , $player] = $this->game();
        $plain = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $plain->assignRole('user');

        UserBank::create([
            'user_id' => $player->id, 'shop_id' => $shop->id, 'currency' => 'EUR', 'is_active' => true,
        ]);

        $rows = User::query()
            ->withExists(['banks as manipulation_active' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->keyBy('id');

        $this->assertTrue((bool) $rows[$player->id]->manipulation_active);
        $this->assertFalse((bool) $rows[$plain->id]->manipulation_active);
    }

    public function test_individual_rtp_on_the_user_bank_overrides_the_shop_rtp(): void
    {
        [$shop, $game, $player] = $this->game();

        UserBank::create([
            'user_id' => $player->id, 'shop_id' => $shop->id, 'currency' => 'EUR',
            'slots' => 1_000, 'is_active' => true, 'temp_rtp' => 40,
        ]);

        $shopBefore = (float) $shop->bank('EUR')->slots;
        $ctx = $this->context($player, $game);
        $this->assertSame(40.0, $ctx->rtpTarget());

        $this->spin($ctx, $game, 50);

        // stake_to_bank is now 40% of every 100 stake, not 90%.
        $this->assertEqualsWithDelta($shopBefore + 40 * 50, (float) $shop->bank('EUR')->fresh()->slots, 1.0);
    }
}
