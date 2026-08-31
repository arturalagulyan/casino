<?php

namespace Tests\Feature;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Models\CurrencyRate;
use App\Models\GameBank;
use App\Models\Jackpot;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\Fx;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankerFxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_fx_converts_via_eur_base(): void
    {
        CurrencyRate::updateOrCreate(['currency' => 'USD'], ['rate' => 1.10]);
        CurrencyRate::updateOrCreate(['currency' => 'GBP'], ['rate' => 0.80]);

        $fx = app(Fx::class);

        $this->assertEqualsWithDelta(110.0, $fx->convert(100, Currency::EUR, Currency::USD), 0.001);
        $this->assertEqualsWithDelta(100.0, $fx->toEur(110, Currency::USD), 0.001);
        // 100 USD -> EUR (÷1.10) -> GBP (×0.80)
        $this->assertEqualsWithDelta(72.727, $fx->convert(100, Currency::USD, Currency::GBP), 0.01);
    }

    public function test_bank_settle_and_overflow_sweep(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $shop = Shop::create([
            'name' => 'B', 'slug' => 'b', 'frontend' => 'default',
            'currency' => 'EUR', 'player_limit' => 1000,
        ]);
        $shop->update(['owner_id' => $admin->id]);
        $bank = GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 900]);

        $banker = app(Banker::class);

        // bet 200 -> +140 to pool, win 0 -> pool 1040, sweep 40 back to limit
        $result = $banker->settleRound($bank, BankType::Slots, bet: 200, win: 0, house: $admin);

        $this->assertEqualsWithDelta(1000.0, $result['bank_after'], 0.001);
        $this->assertEqualsWithDelta(40.0, $result['swept'], 0.001);
        $this->assertDatabaseHas('transactions', ['source' => 'game_bank', 'direction' => 'debit']);
    }

    public function test_jackpot_contribution_respects_percent_and_active_flag(): void
    {
        $shop = Shop::create(['name' => 'J', 'slug' => 'j', 'frontend' => 'default', 'currency' => 'EUR']);
        $jackpot = Jackpot::create([
            'shop_id' => $shop->id, 'name' => 'Grand', 'balance' => 100,
            'contribution_percent' => 2, 'is_active' => true,
        ]);

        $new = app(Banker::class)->contributeToJackpot($jackpot, 500);
        $this->assertEqualsWithDelta(110.0, $new, 0.0001); // 100 + 2% of 500

        $jackpot->update(['is_active' => false]);
        $this->assertEqualsWithDelta(110.0, app(Banker::class)->contributeToJackpot($jackpot->fresh(), 999), 0.0001);
    }
}
