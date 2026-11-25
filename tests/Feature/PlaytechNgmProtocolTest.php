<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\PlaytechRound;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Playtech (NGM) messages are Socket.IO JSON, POSTed one at a time by our
 * WebSocket shim; each is answered with a list of messages. Rounds are
 * recorded at coin 1 and served at the player's coin: the grids as recorded,
 * the credit and money fields × coin; the whole win paid with the paid spin.
 */
class PlaytechNgmProtocolTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function playtechGame(array $extra = []): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestPlaytech', 'title' => 'Test Playtech', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::PlaytechNgm, 'default_denomination' => 1,
            'bonus_config' => ['playtech' => $extra + ['game_key' => 'test_pt', 'game' => 'test', 'rgame' => 'test_ptMobile', 'mult' => 40, 'record_bet' => 40,
                'lines' => 40, 'custom' => null, 'before' => [], 'coins' => [1, 2, 5],
                'init' => [
                    'limits' => ['data' => ['gameGroup' => 'test', 'coinSizes' => [1, 2, 5, 10], 'selectedLines' => 40, 'selectedCoin' => 1, 'funNoticeGames' => 3], 'ID' => 40025],
                    'gameLogin' => [['data' => ['stageName' => 'temple', 'windowId' => 'abc'], 'ID' => 48940], ['data' => ['credit' => 10000, 'windowId' => 'abc'], 'ID' => 40026]],
                ]]],
        ]);
        // bets 0.40 / 0.80 / 2.00 = coins 1 / 2 / 5 at 40 lines; 0.30 isn't a whole coin
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.3, 0.4, 0.8, 2], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'pt'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return list<array<string, mixed>> the messages sent back */
    private function send(GameSession $s, array $msg): array
    {
        return (array) $this->call('POST', "/playtech/{$s->token}", [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($msg))
            ->assertOk()->json();
    }

    /** @param list<array<string, mixed>> $out */
    private function byId(array $out, int $id): ?array
    {
        foreach ($out as $m) {
            if ((int) ($m['ID'] ?? 0) === $id) {
                return $m;
            }
        }

        return null;
    }

    private function spinMsg(int $credit, array $extra = []): array
    {
        return ['data' => ['reelset' => 0, 'spinType' => 'REGULAR', 'display' => [1, 2, 3, 4, 5], 'credit' => $credit, 'balance' => $credit,
            'results' => [1, 2, 3, 4, 5], 'windowId' => 'rec'] + $extra, 'ID' => 40022, 'umid' => 9];
    }

    public function test_the_login_carries_our_balance_coins_and_the_games_own_setup(): void
    {
        [, , $s] = $this->playtechGame();
        $login = $this->send($s, ['sessionToken' => 'x', 'ID' => 10001, 'umid' => 2]);
        $this->assertSame('EUR', $this->byId($login, 10002)['token']['currency']);
        $this->assertSame(10000, $this->byId($login, 10006)['balanceInfo']['totalBalance']);

        $limits = $this->byId($this->send($s, ['gameShortName' => 'test', 'ID' => 40024]), 40025)['data'];
        $this->assertSame([1, 2, 5], $limits['coinSizes']);
        $this->assertSame(1, $limits['selectedCoin']);

        $game = $this->send($s, ['gameShortName' => 'test', 'windowId' => 'W1', 'ID' => 40020]);
        $this->assertSame('temple', $this->byId($game, 48940)['data']['stageName']);
        $this->assertSame('W1', $this->byId($game, 48940)['data']['windowId']);   // our window, not the recorded one
        $this->assertSame(10000, $this->byId($game, 40026)['data']['credit']);
        $main = array_values(array_filter($game, fn ($m) => ($m['ID'] ?? 0) === 40085 && ($m['data']['typeBalance'] ?? null) === 0));
        $this->assertSame(10000, $main[0]['data']['balanceInCents']);

        $this->assertSame([], $this->send($s, ['ID' => 40041]));   // jackpot updates: none
        $this->assertSame([['ID' => 18]], $this->send($s, ['ID' => 19]));
    }

    public function test_a_round_with_free_spins_is_paid_at_the_spin_and_served_scaled(): void
    {
        [, $player, $s] = $this->playtechGame();
        // recorded at coin 1 (bet 40) from 10000: the spin awards 2 free spins (gamewin 120), which win 30 and 0 — 150 in all
        $round = PlaytechRound::create(['game_key' => 'test_pt', 'bet_cents' => 40, 'win_cents' => 150, 'feature' => true, 'start_credit' => 10000, 'steps' => [
            ['t' => 'regular', 'c' => 9960, 'm' => [$this->spinMsg(9960, ['freespins' => ['numFreeSpins' => 2, 'gamewin' => 120, 'freespinwin' => 0]])]],
            ['t' => 'free', 'c' => 10080, 'm' => [$this->spinMsg(10080)]],
            ['t' => 'free', 'c' => 10110, 'm' => [$this->spinMsg(10110)]],
        ]]);
        Cache::put('playtech:force:test_pt', $round->id, 60);
        $this->send($s, ['ID' => 40020, 'windowId' => 'W']);

        // at coin 2 (bet 0.80): the bet taken, the whole win (3.00) paid at once
        $out = $this->send($s, ['bet' => 80, 'numCoins' => 1, 'lines' => array_fill(0, 40, 1), 'spinType' => 'regular', 'windowId' => 'W', 'ID' => 40021]);
        $this->assertEquals(102.2, (float) $player->wallet->fresh()->balance);
        $spin = $this->byId($out, 40022)['data'];
        $this->assertSame(9920, $spin['credit']);                 // 10000 − 80
        $this->assertSame(240, $spin['freespins']['gamewin']);    // × 2
        $this->assertSame([1, 2, 3, 4, 5], $spin['display']);
        $this->assertSame('W', $spin['windowId']);
        $this->assertSame(10160, $this->byId($out, 10006)['balanceInfo']['totalBalance']);   // the next step's credit

        $free = fn () => $this->send($s, ['bet' => 80, 'numCoins' => 1, 'lines' => array_fill(0, 40, 1), 'spinType' => 'free', 'windowId' => 'W', 'ID' => 40021]);
        $this->assertSame(10160, $this->byId($free(), 40022)['data']['credit']);   // 9920 + (10080 − 9960) × 2
        $last = $free();
        $this->assertSame(10220, $this->byId($last, 40022)['data']['credit']);
        $this->assertSame(10220, $this->byId($last, 40085)['data']['balanceInCents']);   // the round's end: 10000 − 80 + 300
        $this->assertEquals(102.2, (float) $player->wallet->fresh()->balance);          // free spins: no bet, no second payout

        // round over: a free spin now is refused
        $this->assertSame(40023, $this->send($s, ['bet' => 80, 'spinType' => 'free', 'windowId' => 'W', 'ID' => 40021])[0]['ID']);
    }

    public function test_a_game_with_stages_is_served_rounds_played_from_the_state_in_play(): void
    {
        [, , $s] = $this->playtechGame();   // the recorded setup: stage temple (no symbols: state "temple|")
        $round = fn (string $from, string $to, string $shown) => PlaytechRound::create(['game_key' => 'test_pt', 'bet_cents' => 40, 'win_cents' => 0,
            'feature' => false, 'start_credit' => 10000, 'stage' => $from, 'next_stage' => $to,
            'steps' => [['t' => 'regular', 'c' => 9960, 'm' => [$this->spinMsg(9960, ['featureName' => $shown])]]]]);
        foreach (range(1, 5) as $i) {
            $round('manor|', 'manor|', 'manor');
            $round('cave|', 'cave|', 'cave');
        }
        $round('temple|', 'manor|', 'temple');   // the only round from the temple: the recording went on to the manor

        $spin = fn () => $this->byId($this->send($s, ['bet' => 40, 'numCoins' => 1, 'lines' => array_fill(0, 40, 1), 'spinType' => 'regular', 'windowId' => 'W', 'ID' => 40021]), 40022)['data']['featureName'];
        $this->send($s, ['ID' => 40020, 'windowId' => 'W']);
        $this->assertSame('temple', $spin());
        foreach (range(1, 8) as $i) {
            $this->assertSame('manor', $spin());   // never the cave, which the recording didn't move to
        }
    }

    public function test_a_bet_that_is_not_a_coin_we_offer_is_refused(): void
    {
        [, $player, $s] = $this->playtechGame();
        PlaytechRound::create(['game_key' => 'test_pt', 'bet_cents' => 40, 'win_cents' => 0, 'feature' => false, 'start_credit' => 10000,
            'steps' => [['t' => 'regular', 'c' => 9960, 'm' => [$this->spinMsg(9960)]]]]);
        foreach ([30, 400, 41] as $bet) {
            $out = $this->send($s, ['bet' => $bet, 'lines' => array_fill(0, 40, 1), 'spinType' => 'regular', 'windowId' => 'W', 'ID' => 40021]);
            $this->assertSame(40023, $out[0]['ID'], "bet {$bet}");
        }
        $this->assertEquals(100, (float) $player->wallet->fresh()->balance);
    }

    public function test_a_feature_request_is_answered_from_the_round_with_the_clients_pick(): void
    {
        [, $player, $s] = $this->playtechGame(['custom' => 48385, 'lines' => 0]);
        $round = PlaytechRound::create(['game_key' => 'test_pt', 'bet_cents' => 40, 'win_cents' => 40, 'feature' => true, 'start_credit' => 10000, 'steps' => [
            ['t' => 'regular', 'c' => 9960, 'm' => [$this->spinMsg(9960, ['bonusTriggered' => true])]],
            ['t' => 'q', 'q' => ['ID' => 49084, 'pickIndex' => 3], 'c' => 9960, 'm' => [['data' => ['pickIndex' => 3, 'prize' => 4, 'windowId' => 'rec'], 'ID' => 49085]]],
        ]]);
        Cache::put('playtech:force:test_pt', $round->id, 60);
        $spin = $this->send($s, ['type' => 'regular', 'bet' => 40, 'windowId' => 'W', 'ID' => 48385]);   // the game's own spin request
        $this->assertTrue($this->byId($spin, 40022)['data']['bonusTriggered']);
        $this->assertSame([], $this->send($s, ['type' => 're', 'bet' => 40, 'windowId' => 'W', 'ID' => 48385]));   // not a spin here

        $pick = $this->byId($this->send($s, ['pickIndex' => 7, 'windowId' => 'W', 'ID' => 49084]), 49085)['data'];
        $this->assertSame(7, $pick['pickIndex']);   // the client's pick, not the recorded one
        $this->assertSame(4, $pick['prize']);
        $this->assertEquals(100.0, (float) $player->wallet->fresh()->balance);   // −0.40 + 0.40
    }

    public function test_an_unknown_session_gets_the_platforms_session_error(): void
    {
        $this->playtechGame();
        $out = $this->call('POST', '/playtech/nope', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ID":10001}')->assertOk()->json();
        $this->assertSame(10003, $out[0]['ID']);
    }
}
