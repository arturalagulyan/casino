<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Models\WazdanRound;
use App\Services\GamePlay\Wazdan\WazdanLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Wazdan frames are AES-encrypted JSON (hex), POSTed by our WebSocket shim.
 * Rounds are recorded at the smallest stake and served at the player's: money
 * fields × stake / recorded stake; the win paid at the paid START, shown in
 * `prize` until the client banks it (PRIZE_TO_BANK); free games follow, unpaid.
 */
class WazdanProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const array INIT = [
        'login' => ['cbe' => 'no', 'pv' => 4, 'session_id' => '0', 'sync_start' => 1, 'user_id' => '0'],
        'LOAD_CLDATA_WEB' => ['currency' => ' ', 'currency_display_as' => ' ', 'show_currency' => 'B'],
        'LIST_PARAMS' => ['demoMode' => 'no', 'maxCredit' => '100000'],
        'GAME_PARAMS' => ['default difficulty' => 2, 'difficulty level mode' => 1, 'coin bonus' => 1],
        'GAME' => ['cashInCredit' => 10000, 'credit' => 10000, 'difficulty_level' => 2, 'risk' => 1, 'rtp' => '96.47',
            'stake' => 10, 'stake_list' => '10,20,30', 'minstake' => 10, 'maxstake' => 50000000000],
        'STATE' => ['bonus' => 0, 'bonus_count' => 0, 'bonus_nr' => 0, 'symb' => '1,2,3,1,2,3,1,2,3', 'bonus_cost' => '100'],
    ];

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function wazdanGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestWazdan', 'title' => 'Test Wazdan', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Wazdan, 'default_denomination' => 1,
            'bonus_config' => ['wazdan' => ['game_key' => 'test_wzdn', 'folder' => 'test_wzdn', 'cmd' => 'JakljASJdjsadIAS',
                'record_stake' => 10, 'stakes' => [10, 20, 30], 'sid' => 'lang=en;host=x/wz/ws/;cmd=JakljASJdjsadIAS;', 'init' => self::INIT]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.1, 0.5, 1], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'wz'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, mixed> the decrypted reply frame */
    private function send(GameSession $s, string $act, array $arg = [], string $service = 'game', int $id = 1): array
    {
        $msg = '{"b":"00000000", "service":"'.$service.'", "act":"'.$act.'", "arg":'.json_encode((object) $arg).', "ts":1, "id":'.$id.'}';
        $hex = $this->call('POST', "/wazdan/{$s->token}", [], [], [], ['CONTENT_TYPE' => 'text/plain'], WazdanLiveClient::encrypt($msg))
            ->assertOk()->getContent();

        return (array) json_decode(WazdanLiveClient::decrypt((string) $hex), true);
    }

    private function step(int $prize, int $count, int $nr, string $flags = '0'): array
    {
        return ['bank' => '0', 'bonus_count' => $count, 'bonus_nr' => $nr, 'bonus_stake' => 10, 'bonusbet' => 10, 'cprize' => '0',
            'credit' => 9990, 'cashInCredit' => 9990, 'flags' => $flags, 'jprize' => '0', 'prize' => $prize, 'symb' => '1,2,3,1,2,3,1,2,3',
            'offsets' => [1, 2, 3], 'bonus_mul' => '3,1,9,3', 'sym_mul' => '0,2,0', 'coins' => 1];
    }

    public function test_the_cipher_round_trips(): void
    {
        $plain = '{"b":"6ee2efe5", "service":"wsp", "act":"setup", "arg":{"cid":"jY0xUfAp"}, "id":1}';
        $hex = WazdanLiveClient::encrypt($plain);
        $this->assertMatchesRegularExpression('/^[0-9A-F]+$/', $hex);
        $this->assertSame(0, strlen($hex) % 32);
        $this->assertSame($plain, WazdanLiveClient::decrypt($hex));
    }

    public function test_the_setup_carries_our_balance_currency_and_stakes(): void
    {
        [, , $s] = $this->wazdanGame();
        $this->assertSame(['pv' => 8, 'ver' => 5], $this->send($s, 'setup', [], 'wsp')['res']);
        $login = $this->send($s, 'JakljASJdjsadIAS', ['cn' => 1], 'game', 2);
        $this->assertSame('ok', $login['s']);
        $this->assertSame(2, $login['id']);
        $this->assertSame('EUR', $this->send($s, 'LOAD_CLDATA_WEB')['res']['currency']);
        $game = $this->send($s, 'GAME')['res'];
        $this->assertSame(10000, $game['credit']);
        $this->assertSame('10,50,100', $game['stake_list']);
        $this->assertSame(10, $game['stake']);
        $this->assertArrayNotHasKey('bonus_cost', $this->send($s, 'STATE')['res']);   // no feature buy
        $this->assertSame(['s' => 'ok'], array_intersect_key($this->send($s, 'READY_TO_PLAY'), ['s' => 1, 'res' => 1]));
    }

    public function test_a_round_with_free_games_is_paid_at_the_start_and_served_scaled(): void
    {
        [, $player, $s] = $this->wazdanGame();
        // recorded at 10¢ from 10000¢: the paid START wins 20 and awards 2 free games (+0, +50): 70 in all
        $round = WazdanRound::create(['game_key' => 'test_wzdn', 'bet_cents' => 10, 'win_cents' => 70, 'feature' => true, 'start_credit' => 10000, 'steps' => [
            $this->step(20, 2, 0), $this->step(20, 2, 1), $this->step(70, 2, 2, '6'),
        ]]);
        Cache::put('wazdan:force:test_wzdn', $round->id, 60);
        $this->send($s, 'GAME');

        // at 0.50 (× 5): stake taken, whole win (3.50) paid at once — shown only once banked
        $r = $this->send($s, 'START', ['stake' => 50, 'coins' => 50])['res'];
        $this->assertEquals(103, (float) $player->wallet->fresh()->balance);
        $this->assertSame(9950, $r['credit']);
        $this->assertSame(9950, $r['cashInCredit']);
        $this->assertSame(100, $r['prize']);
        $this->assertSame(50, $r['bonus_stake']);
        $this->assertSame('3,1,9,3', $r['bonus_mul']);   // multipliers, not money
        $this->assertSame(1, $r['coins']);
        $this->assertSame(2, $r['bonus_count']);

        $r = $this->send($s, 'START', ['stake' => 50])['res'];   // a free game: no stake
        $this->assertSame(9950, $r['credit']);
        $this->assertSame(1, $r['bonus_nr']);
        $r = $this->send($s, 'START', ['stake' => 50])['res'];
        $this->assertSame(350, $r['prize']);
        $this->assertSame('6', $r['flags']);
        $this->assertEquals(103, (float) $player->wallet->fresh()->balance);

        $bank = $this->send($s, 'PRIZE_TO_BANK')['res'];
        $this->assertSame(10300, $bank['credit']);
        $this->assertSame(10300, $this->send($s, 'GET_COUNTERS')['res']['cashInCredit']);
    }

    private function winRound(): void
    {
        $round = WazdanRound::create(['game_key' => 'test_wzdn', 'bet_cents' => 10, 'win_cents' => 20, 'feature' => false,
            'start_credit' => 10000, 'steps' => [$this->step(20, 0, 0, '6')]]);
        Cache::put('wazdan:force:test_wzdn', $round->id, 60);
    }

    public function test_a_won_gamble_doubles_the_win_and_a_lost_one_takes_it(): void
    {
        [$game, $player, $s] = $this->wazdanGame();
        $this->winRound();
        $this->send($s, 'START', ['stake' => 10]);   // pays 0.20: balance 100.10, shown 99.90
        $this->assertSame([], $this->send($s, 'GAMBLING_ENTER', ['type' => 0])['res']);

        $outcomes = [];
        for ($i = 0; $i < 40 && count($outcomes) < 2; $i++) {
            $before = (float) $player->wallet->fresh()->balance;
            $r = $this->send($s, 'GAMBLING_START', ['card_color' => 1]);
            if ($r['s'] !== 'ok') {
                break;   // lost: nothing left at stake
            }
            $g = $r['res'];
            $outcomes[$g['win']] = true;
            if ($g['win'] === 1) {
                $this->assertSame(4, $g['flags']);
                $this->assertEqualsWithDelta($before + $g['prize'] / 200, (float) $player->wallet->fresh()->balance, 0.001);
                $this->assertSame((int) round($player->wallet->fresh()->balance * 100) - $g['prize'], $g['credit']);
            } else {
                $this->assertSame(0, $g['prize']);
                $this->assertSame((int) round($player->wallet->fresh()->balance * 100), $g['credit']);
                $this->assertSame('error', $this->send($s, 'GAMBLING_START', ['card_color' => 1])['s']);
                break;
            }
        }
        $this->assertNotEmpty($outcomes);
    }

    public function test_leaving_the_gamble_banks_the_win(): void
    {
        [, $player, $s] = $this->wazdanGame();
        $this->winRound();
        $this->send($s, 'START', ['stake' => 10]);
        $this->send($s, 'GAMBLING_ENTER', ['type' => 0]);
        $this->assertSame(['flags' => '0'], $this->send($s, 'GAMBLING_LEAVE')['res']);
        $this->assertSame(10010, $this->send($s, 'PRIZE_TO_BANK')['res']['credit']);
        $this->assertEquals(100.1, (float) $player->wallet->fresh()->balance);
        $this->assertSame('error', $this->send($s, 'GAMBLING_START', ['card_color' => 0])['s']);   // nothing at stake any more
    }

    public function test_a_stake_the_game_does_not_offer_is_refused(): void
    {
        [, $player, $s] = $this->wazdanGame();
        $r = $this->send($s, 'START', ['stake' => 20]);
        $this->assertSame('error', $r['s']);
        $this->assertEquals(100, (float) $player->wallet->fresh()->balance);
    }

    public function test_an_unknown_session_gets_an_error_frame(): void
    {
        [, , $s] = $this->wazdanGame();
        $s->update(['is_active' => false]);
        $this->assertSame('error', $this->send($s, 'GAME')['s']);
    }
}
