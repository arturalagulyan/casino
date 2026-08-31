<?php

namespace Database\Seeders;

use App\Enums\ShopStatus;
use App\Enums\UserStatus;
use App\Models\CurrencyRate;
use App\Models\GameBank;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Indicative FX rates (units per 1 EUR) — refresh from a real feed later.
        foreach ([
            'EUR' => 1, 'USD' => 1.08, 'GBP' => 0.85, 'CHF' => 0.95, 'AUD' => 1.65,
            'CAD' => 1.47, 'NOK' => 11.6, 'SEK' => 11.4, 'RUB' => 98, 'UAH' => 44,
            'GEL' => 2.9, 'RON' => 4.97, 'HUF' => 395, 'BRL' => 5.9, 'ARS' => 1000,
            'INR' => 90, 'CNY' => 7.8, 'JPY' => 162, 'KRW' => 1450, 'THB' => 39,
            'ALL' => 99, 'KES' => 140, 'CFA' => 656,
        ] as $code => $rate) {
            CurrencyRate::updateOrCreate(['currency' => $code], ['rate' => $rate, 'quoted_at' => now()]);
        }

        $shop = Shop::firstOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Demo Casino',
                'frontend' => 'default',
                'currency' => 'EUR',
                'status' => ShopStatus::Active,
                'rtp_percent' => 90,
            ],
        );

        GameBank::firstOrCreate(['shop_id' => $shop->id, 'currency' => 'EUR']);

        $adminRole = Role::where('slug', 'admin')->first();

        $admin = User::firstOrCreate(
            ['username' => 'admin', 'shop_id' => null],
            [
                'role_id' => $adminRole?->id,
                'email' => 'arturalagulyan@gmail.com',
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
            ],
        );
        $admin->roles()->syncWithoutDetaching([$adminRole?->id]);
        Wallet::firstOrCreate(['user_id' => $admin->id], ['currency' => 'EUR']);

        if (app()->environment('local', 'testing') && ! app()->runningUnitTests()) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
