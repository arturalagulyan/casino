<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Filament\Resources\GameBanks\Pages\ListGameBanks;
use App\Filament\Resources\GameRounds\Pages\ListGameRounds;
use App\Filament\Resources\Jackpots\Pages\ListJackpots;
use App\Filament\Resources\Shops\Pages\ListShops;
use App\Filament\Resources\Shops\Pages\ViewShop;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTableFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_priority_list_pages_mount_with_filters(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            ListShops::class, ListUsers::class, ListApiKeys::class, ListGameBanks::class,
            ListJackpots::class, ListTransactions::class, ListGameRounds::class,
        ] as $page) {
            Livewire::test($page)->assertOk();
        }
    }

    public function test_shop_currency_and_amount_range_filters_apply(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ListShops::class)
            ->set('tableFilters.currency.value', Currency::EUR->value)
            ->assertOk()
            ->set('tableFilters.balance_range.from', '100')
            ->set('tableFilters.balance_range.to', '5000')
            ->assertOk();
    }

    public function test_user_online_and_currency_filters_apply(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ListUsers::class)
            ->set('tableFilters.online.value', true)
            ->assertOk()
            ->set('tableFilters.currency.value', Currency::USD->value)
            ->assertOk();
    }

    public function test_view_pages_render_money_and_currency(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $shop = Shop::create([
            'name' => 'View Test', 'slug' => 'view-test', 'frontend' => 'default',
            'currency' => Currency::USD->value, 'balance' => 1234.5,
        ]);
        $player = User::factory()->create([
            'shop_id' => $shop->id, 'currency' => Currency::USD->value,
        ]);
        $player->wallet->update(['balance' => 500]);

        Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->assertOk();
        Livewire::test(ViewUser::class, ['record' => $player->getKey()])->assertOk();
    }

    public function test_wallet_currency_follows_user_currency(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['currency' => Currency::GBP->value]);
        $this->assertSame(Currency::GBP, $user->wallet->currency);

        $user->update(['currency' => Currency::USD->value]);
        $this->assertSame(Currency::USD, $user->fresh()->wallet->currency);
    }
}
