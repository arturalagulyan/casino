<?php

namespace Database\Seeders;

use App\Enums\BankType;
use App\Enums\Device;
use App\Enums\ShopStatus;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameRound;
use App\Models\GameTemplate;
use App\Models\Jackpot;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Light, obviously-fake data so the panel isn't empty in local/dev. */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');

        $rows = [
            ['Sweet Bonanza', 'pragmatic', 'high'], ['Gates of Olympus', 'pragmatic', 'high'],
            ['Book of Ra', 'gaminator', 'high'], ['Sizzling Hot', 'gaminator', 'medium'],
            ['Dolphins Pearl', 'merkur', 'medium'], ['Lucky Ladys Charm', 'merkur', 'low'],
        ];

        // Provider / type groupings are Categories (admin-managed), not code.
        $categories = collect(['Slots', 'Pragmatic', 'Gaminator', 'Merkur', 'Wazdan', 'NetGame', 'Arcade'])
            ->mapWithKeys(fn ($t) => [strtolower($t) => Category::firstOrCreate(
                ['shop_id' => null, 'slug' => Str::slug($t)],
                ['title' => $t, 'position' => 0],
            )]);

        /** @var array<string, string> code => provider slug */
        $providerByCode = collect($rows)->mapWithKeys(fn ($t) => [Str::studly($t[0]) => $t[1]])->all();

        $templates = collect($rows)->map(fn ($t) => GameTemplate::firstOrCreate(
            ['code' => Str::studly($t[0])],
            [
                'title' => $t[0],
                'device' => Device::Both->value,
                'bank_type' => BankType::Slots->value,
                'default_denomination' => 1,
                'default_bet_options' => [10, 20, 50, 100, 200],
                'reel_count' => 5,
                'row_count' => 3,
                'symbol_count' => 9,
                'wild_symbol' => 8,
                'scatter_symbol' => 7,
                'wild_multiplier' => 2,
                'symbols' => range(0, 8),
                'has_bonus' => true,
                'has_free_spins' => true,
                'free_spins_count' => 10,
                'free_spins_table' => [0, 0, 0, 10, 15, 20],   // by scatter count
                'free_spins_multiplier' => $t[2] === 'high' ? 3 : 2,
                'has_gamble' => $t[1] !== 'pragmatic',
                'gamble_win_chance' => 3,
                'volatility' => $t[2],
                'rtp_control_window' => 200,
                'paytable' => self::demoPaytable(),
                'reel_strips' => self::demoReelStrips(),
            ],
        ));

        foreach (['Golden Palace' => 'USD', 'Neon Nights' => 'EUR'] as $name => $currency) {
            $shop = Shop::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'frontend' => 'default',
                    'currency' => $currency,
                    'balance' => fake()->randomElement([25000, 50000, 100000]),
                    'status' => ShopStatus::Active,
                    'rtp_percent' => fake()->randomElement([84, 90, 94]),
                ],
            );

            GameBank::firstOrCreate(
                ['shop_id' => $shop->id, 'currency' => $currency],
                [
                    'slots' => fake()->randomFloat(2, 500, 5000),
                    'bonus' => fake()->randomFloat(2, 100, 1500),
                    'little' => fake()->randomFloat(2, 50, 400),
                    'table_bank' => fake()->randomFloat(2, 200, 2000),
                    'fish' => fake()->randomFloat(2, 0, 800),
                ],
            );

            $jackpot = Jackpot::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => 'Mega Jackpot'],
                [
                    'balance' => fake()->randomFloat(2, 2000, 40000),
                    'contribution_percent' => 0.75,
                    'seed_min' => 1000, 'seed_max' => 2000,
                    'payout_min' => 20000, 'payout_max' => 60000,
                ],
            );

            $games = $templates->map(function (GameTemplate $tpl) use ($shop, $jackpot, $categories, $providerByCode) {
                $game = Game::firstOrCreate(
                    ['shop_id' => $shop->id, 'template_id' => $tpl->id],
                    [
                        'title' => $tpl->title,
                        'jackpot_id' => $jackpot->id,
                        'bank_type' => BankType::Slots->value,
                        'denomination' => 1,
                        'bet_options' => [10, 20, 50, 100, 200],
                        'reserve_percent' => fake()->randomElement([2, 4, 6]),
                        'total_bet' => 0, 'total_win' => 0,
                    ],
                );

                $game->categories()->syncWithoutDetaching(array_filter([
                    $categories['slots']?->id,
                    $categories[$providerByCode[$tpl->code] ?? '']?->id,
                ]));

                return $game;
            });

            // cashier
            $cashier = User::firstOrCreate(
                ['shop_id' => $shop->id, 'username' => Str::slug($name).'-cashier'],
                ['role_id' => $roles['cashier'], 'password' => Hash::make('password'), 'status' => UserStatus::Active],
            );
            $cashier->assignRole('cashier');

            // players + play history
            for ($i = 1; $i <= 12; $i++) {
                $player = User::firstOrCreate(
                    ['shop_id' => $shop->id, 'username' => Str::slug($name).'-player'.$i],
                    [
                        'role_id' => $roles['user'],
                        'password' => Hash::make('password'),
                        'status' => UserStatus::Active,
                        'currency' => $currency,
                        'parent_id' => $cashier->id,
                        'last_online_at' => now()->subMinutes(fake()->numberBetween(1, 4000)),
                    ],
                );
                $player->assignRole('user');

                $wallet = Wallet::updateOrCreate(
                    ['user_id' => $player->id],
                    ['currency' => $currency, 'balance' => fake()->randomFloat(2, 0, 800)],
                );

                Transaction::create([
                    'shop_id' => $shop->id,
                    'user_id' => $player->id,
                    'counterparty_id' => $cashier->id,
                    'direction' => TxnDirection::Credit,
                    'source' => TxnSource::Handpay,
                    'currency' => $currency,
                    'amount' => fake()->randomElement([100, 200, 500]),
                    'balance_before' => 0,
                    'title' => 'Cashier deposit',
                    'created_at' => now()->subDays(fake()->numberBetween(0, 6)),
                ]);

                $balance = (float) $wallet->balance;
                foreach (range(1, fake()->numberBetween(5, 40)) as $s) {
                    $game = $games->random();
                    $bet = (float) fake()->randomElement([10, 20, 50, 100]);
                    // ~82% RTP: most spins lose, occasional modest win, rare big hit.
                    $win = match (true) {
                        fake()->boolean(3) => $bet * fake()->randomFloat(2, 6, 18),
                        fake()->boolean(20) => $bet * fake()->randomFloat(2, 1.4, 3.2),
                        default => 0.0,
                    };
                    $balance = max(0, $balance - $bet + $win);
                    $when = now()->subMinutes(fake()->numberBetween(0, 60 * 26));

                    GameRound::create([
                        'shop_id' => $shop->id,
                        'user_id' => $player->id,
                        'game_id' => $game->id,
                        'game_code' => $game->template->code,
                        'currency' => $currency,
                        'bet' => $bet,
                        'win' => $win,
                        'balance_after' => $balance,
                        'stake_to_bank' => round($bet * 0.7, 4),
                        'stake_to_jackpot' => round($bet * 0.05, 4),
                        'stake_to_profit' => round($bet * 0.25, 4),
                        'denomination' => 1,
                        'played_at' => $when,
                    ]);

                    $game->increment('total_bet', $bet);
                    $game->increment('total_win', $win);
                    $game->increment('rounds_count');

                    if ($win > 0) {
                        Transaction::create([
                            'shop_id' => $shop->id, 'user_id' => $player->id,
                            'direction' => TxnDirection::Credit, 'source' => TxnSource::Win,
                            'amount' => $win, 'balance_before' => $balance - $win,
                            'title' => $game->title.' win', 'created_at' => $when,
                        ]);
                    }
                }

                $wallet->update(['balance' => $balance]);
            }
        }
    }

    /** @return array<int, list<int>> symbol => payout per 3/4/5-of-a-kind (idx 2/3/4), × betline */
    private static function demoPaytable(): array
    {
        return [
            0 => [0, 0, 5, 10, 25, 0],   1 => [0, 0, 5, 10, 25, 0],   2 => [0, 0, 5, 15, 40, 0],
            3 => [0, 0, 10, 20, 60, 0],  4 => [0, 0, 15, 40, 100, 0], 5 => [0, 0, 20, 60, 150, 0],
            6 => [0, 0, 25, 100, 250, 0],
            7 => [0, 0, 2, 5, 20, 0],     // scatter — any-position
            8 => [0, 0, 0, 0, 0, 0],      // wild — no own payout
        ];
    }

    /** @return array<string, list<int>> */
    private static function demoReelStrips(): array
    {
        $strips = [];
        // weighted bag: low symbols common, high rare, wild/scatter scarce
        $bag = array_merge(
            array_fill(0, 8, 0), array_fill(0, 8, 1), array_fill(0, 7, 2), array_fill(0, 6, 3),
            array_fill(0, 4, 4), array_fill(0, 3, 5), array_fill(0, 2, 6),
            [7, 7], [8, 8],
        );

        foreach (['', 'Bonus'] as $variant) {
            for ($reel = 1; $reel <= 5; $reel++) {
                $strip = $bag;
                shuffle($strip);
                $strips['reelStrip'.$variant.$reel] = array_values($strip);
            }
        }

        return $strips;
    }
}
