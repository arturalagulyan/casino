<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\CurrencyRate;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Jackpot;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\Fx;
use App\Services\GamePlay\CurrencyScaler;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyScalingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['USD' => 1.10, 'ALL' => 100, 'JPY' => 160] as $code => $rate) {
            CurrencyRate::updateOrCreate(['currency' => $code], ['rate' => $rate]);
        }
        app(Fx::class)->flush();
    }

    // ---- CurrencyScaler ------------------------------------------------

    public function test_scaler_is_a_no_op_when_currencies_match(): void
    {
        $scaler = app(CurrencyScaler::class);

        $this->assertSame(0.10, $scaler->denomination(0.10, Currency::EUR, Currency::EUR));
        $this->assertSame(0.10, $scaler->denomination(0.10, Currency::EUR, null));
    }

    public function test_scaler_converts_and_snaps_to_a_clean_value(): void
    {
        $scaler = app(CurrencyScaler::class);

        // 0.10 EUR × 100 = 10 ALL
        $this->assertSame(10.0, $scaler->denomination(0.10, Currency::EUR, Currency::ALL));
        // 1.00 EUR × 1.10 = 1.10 USD -> snaps to 1.00
        $this->assertSame(1.0, $scaler->denomination(1.00, Currency::EUR, Currency::USD));
        // 0.20 EUR × 160 = 32 JPY -> snaps to 20 (nearest 1/2/5 ×10ⁿ), integer currency
        $this->assertSame(20.0, $scaler->denomination(0.20, Currency::EUR, Currency::JPY));
    }

    // ---- game session ------------------------------------------------

    /** @return array{0: Shop, 1: Game} */
    private function game(): array
    {
        $shop = Shop::create([
            'name' => 'FX', 'slug' => 'fx', 'frontend' => 'default',
            'currency' => 'EUR', 'rtp_percent' => 90, 'player_limit' => 100_000_000, 'max_win_multiplier' => 500,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 500_000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'ALL', 'slots' => 5_000_000]);

        $tpl = GameTemplate::create([
            'code' => 'FxSlot', 'title' => 'FX Slot', 'engine' => 'internal', 'device' => 'both',
            'bank_type' => 'slots', 'default_denomination' => 0.10, 'pricing_currency' => 'EUR',
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 9, 'volatility' => 'medium',
        ]);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 0.10, 'is_visible' => true, 'bet_options' => [10, 20, 50],
        ]);

        return [$shop, $game];
    }

    private function player(Shop $shop, string $currency): User
    {
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => $currency]);
        $player->assignRole('user');
        $player->wallet->update(['currency' => $currency, 'balance' => 500_000]);

        return $player->fresh();
    }

    private function context(User $player, Game $game): GameContext
    {
        return new GameContext($player, $game, app(Ledger::class), app(Banker::class));
    }

    public function test_denomination_is_unchanged_for_a_player_in_the_pricing_currency(): void
    {
        [$shop, $game] = $this->game();
        $ctx = $this->context($this->player($shop, 'EUR'), $game);

        $this->assertSame(0.10, $ctx->denomination());
        $this->assertSame([10.0, 20.0, 50.0], $ctx->betOptions());
    }

    public function test_denomination_scales_by_fx_for_a_player_in_another_currency(): void
    {
        [$shop, $game] = $this->game();
        $ctx = $this->context($this->player($shop, 'ALL'), $game);

        // 0.10 EUR denomination -> 10 ALL; bet ladder (credits) is currency-blind
        $this->assertSame(10.0, $ctx->denomination());
        $this->assertSame([10.0, 20.0, 50.0], $ctx->betOptions());
    }

    public function test_stake_is_100x_larger_in_all_than_in_eur_for_the_same_bet(): void
    {
        [$shop, $game] = $this->game();

        $eur = $this->context($this->player($shop, 'EUR'), $game);
        $all = $this->context($this->player($shop, 'ALL'), $game);

        // stake = lines × betline × denomination
        $eurStake = 10 * 10 * $eur->denomination();   // 10 EUR
        $allStake = 10 * 10 * $all->denomination();   // 1000 ALL

        $this->assertSame(10.0, $eurStake);
        $this->assertSame(1000.0, $allStake);
    }

    public function test_an_all_player_spin_settles_against_the_all_bank(): void
    {
        [$shop, $game] = $this->game();
        $player = $this->player($shop, 'ALL');
        $ctx = $this->context($player, $game);

        $allBankBefore = (float) $shop->bank('ALL')->slots;
        $eurBankBefore = (float) $shop->bank('EUR')->slots;

        $out = app(GameRegistry::class)->for($game)->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);

        $this->assertSame(1000.0, $out['bet']);   // 10 × 10 × 10 ALL denom
        $this->assertSame($eurBankBefore, (float) $shop->bank('EUR')->fresh()->slots);   // EUR pool untouched
        // ALL pool took the stake split (+900 at 90% RTP) and paid any win
        $this->assertEqualsWithDelta($allBankBefore + 900 - $out['win'], (float) $shop->bank('ALL')->fresh()->slots, 1.0);
    }

    // ---- jackpots ---------------------------------------------------

    public function test_pool_currency_falls_back_to_the_shop_currency(): void
    {
        $shop = Shop::create(['name' => 'S', 'slug' => 's', 'frontend' => 'default', 'currency' => 'USD']);
        $global = Jackpot::create(['name' => 'Global', 'balance' => 0, 'contribution_percent' => 1]);
        $shopJp = Jackpot::create(['shop_id' => $shop->id, 'name' => 'Shop', 'balance' => 0, 'contribution_percent' => 1]);
        $explicit = Jackpot::create(['shop_id' => $shop->id, 'name' => 'Fixed', 'currency' => 'EUR', 'balance' => 0, 'contribution_percent' => 1]);

        $this->assertSame(Currency::EUR, $global->poolCurrency());
        $this->assertSame(Currency::USD, $shopJp->poolCurrency());
        $this->assertSame(Currency::EUR, $explicit->poolCurrency());
    }

    public function test_jackpot_contribution_converts_the_stake_into_the_pool_currency(): void
    {
        $shop = Shop::create(['name' => 'J', 'slug' => 'j', 'frontend' => 'default', 'currency' => 'EUR']);
        $jackpot = Jackpot::create([
            'shop_id' => $shop->id, 'name' => 'Grand', 'currency' => 'EUR',
            'balance' => 100, 'contribution_percent' => 2, 'is_active' => true,
        ]);

        // 1000 ALL stake ÷ 100 = 10 EUR; 2% = 0.20 EUR
        $new = app(Banker::class)->contributeToJackpot($jackpot, 1000, Currency::ALL);

        $this->assertEqualsWithDelta(100.20, $new, 0.0001);
    }

    public function test_jackpot_payout_converts_the_pool_into_the_winner_currency(): void
    {
        $shop = Shop::create(['name' => 'P', 'slug' => 'p', 'frontend' => 'default', 'currency' => 'EUR']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $winner = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'ALL']);
        $winner->assignRole('user');
        $winner->wallet->update(['currency' => 'ALL', 'balance' => 0]);

        $jackpot = Jackpot::create([
            'shop_id' => $shop->id, 'name' => 'Mega', 'currency' => 'EUR',
            'balance' => 500, 'contribution_percent' => 1, 'is_active' => true,
        ]);

        $txn = app(Ledger::class)->payoutJackpot($jackpot, $winner, $admin);

        // 500 EUR pool × 100 = 50 000 ALL to the winner
        $this->assertEqualsWithDelta(50_000, (float) $winner->wallet->fresh()->balance, 0.01);
        $this->assertSame(Currency::ALL, $txn->currency);
        $this->assertEqualsWithDelta(50_000, (float) $txn->amount, 0.01);
        // pool history keeps the amount in pool currency
        $this->assertSame('0.000000', $jackpot->fresh()->balance);
        $this->assertEqualsWithDelta(500, (float) $jackpot->fresh()->last_won_amount, 0.01);
        $this->assertDatabaseHas('transactions', ['source' => 'jackpot', 'currency' => 'ALL']);
    }

    public function test_placebet_feeds_the_pool_the_converted_amount_but_splits_in_player_currency(): void
    {
        [$shop, $game] = $this->game();
        $game->update(['jackpot_id' => ($jp = Jackpot::create([
            'shop_id' => $shop->id, 'name' => 'Pot', 'currency' => 'EUR',
            'balance' => 0, 'contribution_percent' => 10, 'is_active' => true,
        ]))->id]);

        $ctx = $this->context($this->player($shop, 'ALL'), $game);
        app(GameRegistry::class)->for($game)->handle($ctx, ['command' => 'bet', 'bet' => 10, 'lines' => 10]);

        // stake 1000 ALL -> 10 EUR -> 10% = 1 EUR into the pool
        $this->assertEqualsWithDelta(1.0, (float) $jp->fresh()->balance, 0.001);
    }
}
