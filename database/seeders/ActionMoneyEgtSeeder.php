<?php

namespace Database\Seeders;

use App\Enums\BankType;
use App\Enums\GameEngine;
use App\Enums\UserStatus;
use App\Enums\Volatility;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\BundleManager;
use App\Services\SeamlessWallet\GameLaunch;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The real EGT "Action Money" running on the rebuild — as pure DB config. Every
 * number (paytable, 30 paylines, reel strips, win chances, feature flows) lives
 * on the game_templates row; the WebSocket protocol comes from the "Egt"
 * category. All editable in the admin panel; there is no game-specific code.
 *
 *   php artisan db:seed --class=ActionMoneyEgtSeeder
 */
class ActionMoneyEgtSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Shop::query()->firstOrFail();

        // Fund the slots pool so wins can be paid.
        $bank = $shop->banks()->firstOrCreate(['currency' => $shop->currency->value]);
        if ((float) $bank->slots < 50_000) {
            $bank->forceFill(['slots' => 100_000])->save();
        }

        // The "Egt" category carries the shared client protocol — every game
        // tagged with it plays over the EGT GamePlatform WebSocket.
        $egt = Category::updateOrCreate(
            ['shop_id' => $shop->id, 'slug' => 'egt'],
            ['title' => 'Egt', 'position' => 3, 'config' => ['client_protocol' => 'game_platform']],
        );

        $template = GameTemplate::updateOrCreate(
            ['code' => 'ActionMoneyEGT'],
            [
                'title' => 'Action Money',
                'engine' => GameEngine::Internal,
                'device' => 'both',
                'bank_type' => BankType::Slots,
                'default_bet_options' => [1, 2, 5, 10, 20],   // legacy Server.php $gameBets (client bet selector)
                'default_denomination' => 1,
                'poster_path' => $this->poster(),

                'reel_count' => 5,
                'row_count' => 3,
                'symbol_count' => 9,
                'symbols' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
                'wild_symbol' => 8,        // pays and substitutes
                'scatter_symbol' => 10,    // 3+ → pick multiplier → pick free spins
                'bonus_symbol' => 9,       // 3+ → money pick bonus
                'wild_multiplier' => 1,
                'min_match' => 2,          // Action Money pays 2-of-a-kind

                'has_bonus' => true,
                'has_free_spins' => true,
                'free_spins_count' => 10,
                'free_spins_table' => [0, 0, 0, 7, 8, 10],
                'free_spins_multiplier' => 1,
                'has_gamble' => true,
                'gamble_type' => 1,
                'gamble_win_chance' => 4,   // legacy w_games.rezerv
                'volatility' => Volatility::High,
                'rtp_control_window' => 200,

                'paytable' => [
                    // index = match count (0..5); Action Money pays counts 2..5
                    0 => [0, 0, 0, 5, 20, 100],
                    1 => [0, 0, 0, 5, 20, 100],
                    2 => [0, 0, 0, 5, 20, 100],
                    3 => [0, 0, 0, 10, 50, 200],
                    4 => [0, 0, 0, 10, 50, 200],
                    5 => [0, 0, 0, 10, 50, 200],
                    6 => [0, 0, 2, 20, 100, 1000],
                    7 => [0, 0, 2, 20, 100, 1000],
                    8 => [0, 0, 10, 100, 2000, 10000],
                    9 => [0, 0, 0, 0, 0, 0],
                    10 => [0, 0, 0, 0, 0, 0],
                ],

                'reel_strips' => [
                    'reelStrip1' => [6, 4, 6, 1, 1, 0, 10, 8, 0, 3, 3, 4, 6, 4, 9, 8, 2, 1, 5, 5, 7],
                    'reelStrip2' => [6, 2, 0, 0, 6, 9, 0, 3, 8, 3, 1, 1, 7, 4, 4, 8, 2, 2, 5, 8, 5],
                    'reelStrip3' => [3, 6, 9, 2, 2, 8, 3, 3, 7, 4, 4, 10, 6, 0, 0, 5, 8, 5, 1, 1],
                    'reelStrip4' => [6, 5, 5, 3, 3, 8, 7, 5, 6, 9, 4, 4, 1, 8, 1, 2, 2, 0, 0, 0],
                    'reelStrip5' => [6, 1, 5, 7, 5, 8, 0, 0, 3, 6, 3, 4, 10, 8, 2, 6, 2, 3, 9, 1, 1],
                    'reelStripBonus1' => [6, 4, 6, 1, 1, 0, 8, 0, 3, 3, 4, 6, 4, 0, 8, 10, 2, 1, 5, 5, 7],
                    'reelStripBonus2' => [6, 2, 0, 0, 6, 0, 3, 8, 3, 1, 1, 7, 4, 4, 0, 8, 2, 2, 5, 8, 5],
                    'reelStripBonus3' => [3, 6, 0, 2, 2, 8, 3, 3, 7, 10, 4, 4, 6, 0, 0, 5, 8, 5, 1, 1],
                    'reelStripBonus4' => [6, 5, 5, 3, 3, 8, 7, 5, 6, 0, 4, 4, 1, 8, 1, 2, 2, 0, 0, 0],
                    'reelStripBonus5' => [6, 1, 5, 7, 5, 8, 0, 0, 3, 10, 6, 3, 4, 8, 2, 6, 2, 3, 0, 1, 1],
                ],

                // 30 paylines, row index 0..2 per reel (legacy $linesId, 1-indexed − 1)
                'paylines' => array_map(
                    fn (array $l) => array_map(fn (int $r) => $r - 1, $l),
                    [
                        [2, 2, 2, 2, 2], [1, 1, 1, 1, 1], [3, 3, 3, 3, 3], [1, 2, 3, 2, 1], [3, 2, 1, 2, 3],
                        [1, 1, 2, 3, 3], [3, 3, 2, 1, 1], [2, 3, 3, 3, 2], [2, 1, 1, 1, 2], [1, 2, 2, 2, 1],
                        [3, 2, 2, 2, 3], [2, 3, 2, 1, 2], [2, 1, 2, 3, 2], [1, 2, 1, 2, 1], [3, 2, 3, 2, 3],
                        [2, 2, 3, 2, 2], [2, 2, 1, 2, 2], [1, 3, 1, 3, 1], [3, 1, 3, 1, 3], [2, 1, 3, 1, 2],
                        [2, 3, 1, 3, 2], [1, 1, 3, 1, 1], [3, 3, 1, 3, 3], [1, 3, 3, 3, 1], [3, 1, 1, 1, 3],
                        [1, 1, 2, 1, 1], [3, 3, 2, 3, 3], [1, 3, 2, 3, 1], [3, 1, 2, 1, 3], [2, 3, 2, 3, 2],
                    ],
                ),

                // EXACT legacy w_games.lines_percent_config_{spin,bonus} for
                // ActionMoneyEGT (pulled from the live casino-api DB). 1/N chance
                // of a paying spin / feature, by active line-count bucket ×
                // target-RTP band. Band comes from the game's rtp_percent:
                //   <= 80 → 74_80   81-88 → 82_88   >= 89 → 90_96
                // LOWER N = wins land more often. The live shops run percent 74
                // (→ 74_80, 1-in-10 spins) for a stingy floor; raise rtp_percent
                // for a looser game.
                'win_chances' => [
                    'spin' => [
                        'line1' => ['74_80' => 15, '82_88' => 9, '90_96' => 7],
                        'line3' => ['74_80' => 15, '82_88' => 9, '90_96' => 7],
                        'line5' => ['74_80' => 12, '82_88' => 8, '90_96' => 6],
                        'line7' => ['74_80' => 12, '82_88' => 8, '90_96' => 6],
                        'line9' => ['74_80' => 10, '82_88' => 7, '90_96' => 5],
                        'line10' => ['74_80' => 10, '82_88' => 7, '90_96' => 5],
                    ],
                    'bonus' => [
                        'line1' => ['74_80' => 100, '82_88' => 50, '90_96' => 40],
                        'line3' => ['74_80' => 100, '82_88' => 50, '90_96' => 40],
                        'line5' => ['74_80' => 100, '82_88' => 50, '90_96' => 40],
                        'line7' => ['74_80' => 50, '82_88' => 40, '90_96' => 30],
                        'line9' => ['74_80' => 50, '82_88' => 40, '90_96' => 30],
                        'line10' => ['74_80' => 50, '82_88' => 40, '90_96' => 30],
                    ],
                ],

                'bonus_config' => [
                    'triggers' => [
                        '10' => ['flow' => 'pick_multiplier_freespins', 'min' => 3],
                        '9' => ['flow' => 'pick_money', 'min' => 3],
                    ],
                    'pick_multiplier_freespins' => [
                        'multiplier_range' => [1, 5], 'multiplier_picks' => 4,
                        'free_spins_range' => [5, 12], 'extra_wild_range' => [5, 7], 'freespin_picks' => 8,
                    ],
                    'pick_money' => [
                        'multipliers' => [2, 2, 2, 2, 2, 2, 4, 4, 4, 6, 6, 6, 6, 8, 8, 8, 8, 10, 12, 14],
                        'picks' => 3, 'extra_pick_at' => ['4' => 4, '5' => 5],
                    ],
                    'gamble' => ['type' => 'red_black', 'steps' => 5],
                ],

                'layout' => ['egt' => ['game_type' => 'AMJSlot', 'gin' => 851]],

                'is_active' => true,
            ],
        );

        $game = Game::updateOrCreate(
            ['shop_id' => $shop->id, 'template_id' => $template->id],
            [
                'title' => 'Action Money',
                'bank_type' => BankType::Slots,
                // Target RTP % — picks the win-chance band and steers the loop.
                // 92 → 90_96 band (1-in-5 spins), the authentic "good shop" EGT
                // feel; drop toward 74 for the stingy live-shop config.
                'rtp_percent' => 92,
                'max_win_multiplier' => 50,
                'reserve_percent' => 4,          // legacy rezerv (gamble 1/N)
                'bet_options' => [1, 2, 5, 10, 20],  // legacy Server.php $gameBets
                'denomination' => 1,
                'is_visible' => true,
            ],
        );

        $game->categories()->syncWithoutDetaching([$egt->id]);

        // Demo player + API key so the launch flow can be exercised end to end.
        $player = User::where('shop_id', $shop->id)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'user'))
            ->first()
            ?? tap(User::create([
                'shop_id' => $shop->id,
                'username' => 'amdemo',
                'email' => 'amdemo@example.test',
                'password' => bcrypt('password'),
                'currency' => $shop->currency,
                'status' => UserStatus::Active,
            ]), fn (User $u) => $u->assignRole('user'));

        $player->wallet()->update(['currency' => $shop->currency, 'balance' => 5_000]);

        $apiKey = ApiKey::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Demo integration'],
            ['key' => 'demo_'.Str::random(24), 'secret' => Str::random(40), 'is_active' => true],
        );

        // Upload the bundle once from the repo drop-point.
        $zip = storage_path('app/bundles/ActionMoneyEGT.zip');
        if (! $template->activeBundle && is_file($zip)) {
            app(BundleManager::class)->store(
                $template,
                new UploadedFile($zip, 'ActionMoneyEGT.zip', 'application/zip', null, true),
                null,
                'index.html',
                'EGT ActionMoneyEGT GamePlatform front-end (patched entry).',
            );
        }

        $token = app(GameLaunch::class)->issueToken($player->refresh(), $game);

        $this->command?->info('Action Money ready.');
        $this->command?->info("  api key : {$apiKey->key}  (shop {$shop->id})");
        $this->command?->info("  player  : {$player->username}  balance {$player->wallet->balance} {$shop->currency->value}");
        $this->command?->info('  launch  : '.app(GameLaunch::class)->launchUrl($token, 'ActionMoneyEGT'));
        $this->command?->line('');
        $this->command?->line('  External launch:');
        $this->command?->line('    curl -X POST '.url('/api/game/launch').' \\');
        $this->command?->line("      -H 'X-Api-Key: {$apiKey->key}' \\");
        $this->command?->line("      -d player_id=42 -d player_name=Tester -d balance=5000 -d currency={$shop->currency->value} -d game=ActionMoneyEGT");
    }

    /** @return array<string, array<string, int>> */
    private function fillBuckets(array $band): array
    {
        return array_fill_keys(['line1', 'line3', 'line5', 'line7', 'line9', 'line10'], $band);
    }

    /**
     * Copy the bundled Action Money poster (legacy /frontend/Default/ico/…) onto
     * the public disk and return its relative path for game_templates.poster_path.
     */
    private function poster(): ?string
    {
        $src = database_path('seeders/assets/ActionMoneyEGT.jpg');
        if (! is_file($src)) {
            return null;
        }

        $rel = 'game-posters/ActionMoneyEGT.jpg';
        Storage::disk('public')->put($rel, (string) file_get_contents($src));

        return $rel;
    }
}
