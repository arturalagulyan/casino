<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\BundleManager;
use App\Services\GamePlay\SocketServer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The EGT "GamePlatform" WebSocket protocol — one generic adapter. The game's
 * template holds the maths; the "Egt" category supplies the wire protocol. No
 * per-game (or per-provider) code.
 */
class EgtProtocolTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: Game, 2: User, 3: ApiKey} */
    private function egtGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Egt', 'slug' => 'egt', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 92, 'max_win_multiplier' => 500, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $tpl = GameTemplate::create([
            'code' => 'ActionMoneyEGT', 'title' => 'Action Money',
            'engine' => 'internal',
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 9,
            'symbols' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
            'wild_symbol' => 8, 'scatter_symbol' => 10, 'bonus_symbol' => 9,
            'min_match' => 2, 'has_bonus' => true, 'has_free_spins' => true, 'has_gamble' => true,
            'gamble_win_chance' => 2, 'volatility' => 'high',
            'paytable' => [
                0 => [0, 0, 0, 5, 20, 100], 6 => [0, 0, 2, 20, 100, 1000],
                8 => [0, 0, 10, 100, 2000, 10000], 9 => [0, 0, 0, 0, 0, 0], 10 => [0, 0, 0, 0, 0, 0],
            ],
            'reel_strips' => ['reelStrip1' => [6, 4, 6, 1, 1, 0, 10, 8, 0, 3, 3, 4, 6, 4, 9, 8, 2, 1, 5, 5, 7]],
            'paylines' => array_fill(0, 20, [1, 1, 1, 1, 1]),
            'bonus_config' => [
                'triggers' => [
                    '10' => ['flow' => 'pick_multiplier_freespins', 'min' => 3],
                    '9' => ['flow' => 'pick_money', 'min' => 3],
                ],
                'pick_money' => ['multipliers' => [2, 4, 6], 'picks' => 3],
                'gamble' => ['type' => 'red_black', 'steps' => 5],
            ],
            'layout' => ['egt' => ['game_type' => 'AMJSlot', 'gin' => 851]],
        ]);

        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [1, 2, 5, 10, 20],
            'rtp_percent' => 92, 'max_win_multiplier' => 500,
        ]);

        // the wire protocol comes from the category, not the template
        $egt = Category::create(['shop_id' => $shop->id, 'title' => 'Egt', 'slug' => 'egt', 'config' => ['client_protocol' => 'game_platform']]);
        $game->categories()->attach($egt);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 10000]);

        $apiKey = ApiKey::create(['shop_id' => $shop->id, 'key' => 'k_test', 'is_active' => true]);

        return [$shop, $game, $player, $apiKey];
    }

    private function frame(GameSession $session, array $payload): array
    {
        return app(SocketServer::class)->handle(':::'.json_encode(
            $payload + ['sessionId' => $session->token, 'messageId' => 'r-r_'.uniqid()],
        ));
    }

    private function openSession(User $player, Game $game): GameSession
    {
        return GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id,
            'token' => 'egt-sess', 'is_active' => true,
        ]);
    }

    public function test_bad_session_is_rejected(): void
    {
        $this->egtGame();
        $out = app(SocketServer::class)->handle(':::'.json_encode(['command' => 'login', 'sessionId' => 'nope']));

        $this->assertStringContainsString('invalid login', $out[0]);
    }

    public function test_login_settings_subscribe_come_from_the_db(): void
    {
        [, $game, $player] = $this->egtGame();
        $session = $this->openSession($player, $game);

        $login = json_decode($this->frame($session, ['command' => 'login'])[0], true);
        $this->assertSame('login', $login['command']);
        $this->assertSame(1_000_000, $login['balance']);           // 10000 EUR in cents
        $this->assertArrayHasKey('AMJSlot', $login['complex']);    // generated from the template

        $settings = json_decode($this->frame($session, ['command' => 'settings'])[0], true);
        $this->assertSame([1, 2, 5, 10, 20], $settings['complex']['bets']);
        $this->assertArrayHasKey('8', $settings['complex']['paytableCoef']);   // wild pays

        $subscribe = json_decode($this->frame($session, ['command' => 'subscribe'])[0], true);
        $this->assertSame('idle', $subscribe['complex']['currentState']['state']);
    }

    public function test_a_bet_debits_the_wallet_and_records_a_round(): void
    {
        [, $game, $player] = $this->egtGame();
        $session = $this->openSession($player, $game);

        $out = json_decode($this->frame($session, [
            'command' => 'bet', 'gameIdentificationNumber' => 851,
            'bet' => ['gameCommand' => 'bet', 'bet' => 100, 'lines' => 20, 'bonus' => 'false'],
        ])[0], true);

        $this->assertSame('bet', $out['command']);
        $this->assertContains($out['state'], ['idle', 'gamble', 'freespin', 'multiplierchoice', 'bonuschoice']);
        $this->assertGreaterThanOrEqual(0, $out['winAmount']);

        $round = $player->rounds()->latest('id')->first();
        $this->assertNotNull($round);
        $this->assertSame('20.0000', $round->bet);   // 1.00 betline × 20 lines

        // wallet moved by exactly the ledger transactions
        $signed = $player->transactions()->get()->sum(
            fn ($t) => ($t->direction->value === 'debit' ? -1 : 1) * (float) $t->amount,
        );
        $this->assertEqualsWithDelta(10000 + $signed, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_full_launch_flow_via_api_key_injects_the_socket_session(): void
    {
        [, $tplGame, , $apiKey] = $this->egtGame();
        $this->uploadBundle($tplGame->template);

        $res = $this->postJson('/api/game/launch', [
            'player_id' => 'ext-1', 'player_name' => 'Ext', 'balance' => 500,
            'currency' => 'EUR', 'game' => 'ActionMoneyEGT',
        ], ['X-Api-Key' => $apiKey->key])->assertOk();

        $url = $res->json('launch_url');
        $this->assertStringContainsString('/games/ActionMoneyEGT?token=', $url);

        // the launch page injects this launch's session token for the socket
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertOk()
            ->assertSee("sessionStorage.setItem('sessionId'", false);

        $this->assertDatabaseHas('game_sessions', ['token' => GameSession::latest('id')->first()->token]);
    }

    private function uploadBundle(GameTemplate $template): void
    {
        Storage::fake('game_bundles');

        $zipPath = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body></body></html>');
        $zip->close();

        app(BundleManager::class)->store(
            $template,
            new UploadedFile($zipPath, 'b.zip', 'application/zip', null, true),
        );
    }

    public function test_bank_bonus_pick_flows_into_the_free_games_and_never_freezes(): void
    {
        [, $game, $player] = $this->egtGame();
        $session = $this->openSession($player, $game);
        $player->wallet->update(['balance' => 100000]);

        // Simulate a spin that landed 3 BANK BONUS symbols: the client is now
        // sitting on the bank-pick screen.
        $session->update(['state' => ['features' => [
            'bonus_bet' => 20.0, 'bonus_symbol' => 9, 'bonus_scatter_count' => 3,
            'bonus_step' => 'money', 'total_win' => 0.0, 'bonus_win' => 0.0,
            'last_bet' => 100, 'last_lines' => 20, 'multiplier' => 1, 'extra_wild' => -1,
            'free_spins_left' => 0, 'free_spins_used' => 0,
        ]]]);

        $step = fn (array $bet) => json_decode($this->frame($session, [
            'command' => 'bet', 'gameIdentificationNumber' => 851, 'bet' => $bet,
        ])[0], true);

        // pick a bank → must lead into the multiplier pick, not freeze
        $r = $step(['gameCommand' => 'bonuschoice', 'choice' => 1]);
        $this->assertArrayNotHasKey('responseEvent', $r, 'bank pick returned an error');
        $this->assertSame('multiplierchoice', $r['state']);
        $this->assertGreaterThan(0, $r['winAmount']);          // instant cash paid

        // pick a multiplier → free-spin count pick. `closed` must sit INSIDE
        // `choice` (empty here) or the client crashes reading choice.closed.
        $r = $step(['gameCommand' => 'multiplierchoice', 'choice' => 0]);
        $this->assertSame('freespinchoice', $r['state']);
        $this->assertArrayHasKey('closed', $r['complex']['choice']);
        $this->assertSame([], $r['complex']['choice']['closed']);

        // pick free spins → free games start; choice.closed now reveals every box
        $r = $step(['gameCommand' => 'freespinchoice', 'choice' => 0]);
        $this->assertSame('freespin', $r['state']);
        $this->assertNotEmpty($r['complex']['choice']['closed']);

        // play the free games out — each is a normal bet with bonus:true
        for ($i = 0; $i < 30; $i++) {
            $r = $step(['gameCommand' => 'bet', 'bet' => 100, 'lines' => 20, 'bonus' => 'true']);
            $this->assertArrayNotHasKey('responseEvent', $r, "free spin {$i} errored");
            if (in_array($r['state'], ['idle', 'gamble'], true)) {
                break;
            }
        }
        $this->assertContains($r['state'], ['idle', 'gamble'], 'free games never ended');

        // collect settles everything back to idle
        $r = $step(['gameCommand' => 'collect']);
        $this->assertSame('idle', $r['state']);
    }

    public function test_gamble_is_wallet_consistent_and_never_records_a_negative_win(): void
    {
        [, $game, $player] = $this->egtGame();
        $session = $this->openSession($player, $game);
        $player->wallet->update(['balance' => 100000]);

        // spin until we have something to gamble
        for ($i = 0; $i < 60; $i++) {
            $out = json_decode($this->frame($session, [
                'command' => 'bet', 'gameIdentificationNumber' => 851,
                'bet' => ['gameCommand' => 'bet', 'bet' => 100, 'lines' => 20, 'bonus' => 'false'],
            ])[0], true);
            if (($out['state'] ?? null) === 'gamble') {
                break;
            }
        }

        if (($out['state'] ?? null) === 'gamble') {
            $this->frame($session, [
                'command' => 'bet', 'gameIdentificationNumber' => 851,
                'bet' => ['gameCommand' => 'gamble', 'color' => '1'],
            ]);
        }

        $this->assertSame(0, $player->rounds()->where('win', '<', 0)->count());

        $signed = $player->transactions()->get()->sum(
            fn ($t) => ($t->direction->value === 'debit' ? -1 : 1) * (float) $t->amount,
        );
        $this->assertEqualsWithDelta(100000 + $signed, (float) $player->wallet->fresh()->balance, 0.01);
    }
}
