<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\NetEntRound;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\NetEnt\IncompleteRound;
use App\Services\GamePlay\NetEnt\NetEntLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NetEnt servlet replies are recorded at bet level 1 / coin value 1 cent and
 * served at the player's: coins × level, cents × level × coin value, the
 * balance ours — and the round's win is paid once, at the spin.
 */
class NetEntProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const string INIT = 'clientaction=init&credit=10000&bet.betlevel=1&bet.denomination=1&bet.betlines=30&betlevel.all=1,2,3,4,5&betlevel.standard=1&denomination.all=1,2,5,10,20,50&denomination.standard=1&playercurrencyiso=NAN&jackpotcurrencyiso=NAN&playforfun=false&staticsharedurl=https://static.example/nth/gameclient_html/devicedetection/current&bl.i0.coins=1&gameover=true&nextaction=spin';

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function netentGame(array $netent = []): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'N', 'slug' => 'n', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestNetEnt', 'title' => 'Test NetEnt', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::NetEnt, 'default_denomination' => 1,
            'bonus_config' => ['netent' => $netent + ['game_id' => 'test_game', 'lines' => 30, 'denominations' => [1, 2, 5, 10, 20, 50],
                'levels' => [1, 2, 3, 4, 5], 'languages' => ['en'], 'init' => self::INIT, 'paytable' => 'clientaction=paytable&pt.i0.id=basic']],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.3, 0.6, 0.9, 1.5], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'ne'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, string> */
    private function servlet(GameSession $s, string $query): array
    {
        $body = $this->get("/netent/{$s->token}/servlet/CasinoGameServlet;jsession={$s->token}?{$query}&sessid={$s->token}&gameId=test_game")
            ->assertOk()->getContent();

        return NetEntRound::fields((string) $body);
    }

    public function test_bet_lines_are_read_as_a_count_or_as_a_list_of_line_ids(): void
    {
        $this->assertSame(30, NetEntRound::lineCount(['bet.betlines' => '30']));
        $this->assertSame(20, NetEntRound::lineCount(['bet.betlines' => implode(',', range(0, 19))]));
        $this->assertSame(0, NetEntRound::lineCount([]));
    }

    public function test_a_spin_sends_the_lines_or_ways_the_init_offers(): void
    {
        $this->assertSame('0-29', NetEntLiveClient::betLines(['bet.betlines' => '30', 'bl.i0.id' => '0', 'bl.i29.id' => '29']));
        // a list of every line id goes as a range — Secrets of Christmas reads a list as one line
        $this->assertSame('0-24', NetEntLiveClient::betLines(['bet.betlines' => implode(',', range(0, 24))]));
        $this->assertSame('11,12,13', NetEntLiveClient::betLines(['bet.betlines' => '11,12,13']));
        // ways games: one bet option, id 0
        $this->assertSame('0', NetEntLiveClient::betLines(['bet.betlines' => '0', 'bl.i0.id' => '0', 'bl.i0.coins' => '25']));
        // Ghost Pirates: 3/9/27/81/243 ways to choose from — all of them
        $this->assertSame('243', NetEntLiveClient::betLines(['bet.betlines' => '243', 'bl.i0.id' => '3', 'bl.i4.id' => '243']));
    }

    private function liveClient(array $replies): NetEntLiveClient
    {
        Http::fake(['nt.example/*' => Http::sequence(array_map(fn ($r) => Http::response($r), $replies))]);

        return new NetEntLiveClient('https://static.example/nth/g/game/index.html?server=https%3A%2F%2Fnt.example%2Fnth%2F&sessId=s1&gameId=g');
    }

    public function test_a_recorded_round_follows_nextaction_past_gameover_and_measures_its_stake(): void
    {
        // Hook's Heroes: the spin says gameover=true, yet respins follow
        $client = $this->liveClient([
            'clientaction=init&credit=10000&nextaction=spin',
            'clientaction=spin&credit=9980&gameover=true&nextaction=respin',
            'clientaction=respin&credit=10030&totalwin.coins=50&totalwin.cents=50&gameover=true&nextaction=spin',
        ]);
        $client->init();
        $round = $client->round('0');

        $this->assertSame(['spin', 'respin'], array_column($round['steps'], 'sent'));
        $this->assertSame(20, $round['stake']);   // 10000 - 10030 + 50
        $this->assertSame(50, NetEntRound::winOf($round['steps']));
    }

    public function test_a_step_the_server_rejects_makes_the_round_incomplete(): void
    {
        // a pick bonus sent without the player's choice: a bare reply
        $client = $this->liveClient([
            'clientaction=init&credit=10000&nextaction=spin',
            'clientaction=spin&credit=9990&totalwin.coins=0&nextaction=bonusaction',
            'clientaction=bonusaction',
        ]);
        $client->init();

        $this->expectException(IncompleteRound::class);
        $client->round('0');
    }

    public function test_a_bet_option_other_than_the_recorded_one_is_refused_without_charging(): void
    {
        // Hotline, recorded with one hotline (15 coins a spin)
        [, $player, $session] = $this->netentGame(['game_id' => 'hotline', 'lines' => 30, 'bet_coins' => 15]);
        NetEntRound::create(['game_key' => 'hotline', 'bet_coins' => 15, 'win_coins' => 0, 'start_credit' => 10000,
            'created_at' => now(), 'steps' => [['sent' => 'spin', 'reply' => 'clientaction=spin&credit=9985&totalwin.coins=0&nextaction=spin']]]);

        $refused = $this->servlet($session, 'action=spin&bet.betlevel=2&bet.denomination=5&bet.betlines=0-29&bet.hotlines=3&bettingmode=cash');
        $this->assertArrayHasKey('errorcode', $refused);
        $this->assertEqualsWithDelta(100, (float) $player->wallet->fresh()->balance, 0.001);

        $this->assertArrayNotHasKey('errorcode', $this->servlet($session, 'action=spin&bet.betlevel=2&bet.denomination=5&bet.betlines=0-29&bet.hotlines=1&bettingmode=cash'));
        $this->assertEqualsWithDelta(100 - 1.5, (float) $player->wallet->fresh()->balance, 0.001);   // 15 × 2 × 5¢
    }

    public function test_a_round_recorded_at_two_cents_is_rescaled_from_its_coin_value(): void
    {
        // Serengeti Kings offers 2¢ and up: recorded at 2¢, 10 coins a spin
        [, $player, $session] = $this->netentGame(['bet_coins' => 10, 'record_denomination' => 2]);
        NetEntRound::create(['game_key' => 'test_game', 'bet_coins' => 10, 'win_coins' => 40, 'feature' => true, 'start_credit' => 10000, 'created_at' => now(),
            'steps' => [
                ['sent' => 'spin', 'reply' => 'clientaction=spin&credit=9980&totalwin.coins=0&totalwin.cents=0&nextaction=freespin'],
                ['sent' => 'freespin', 'reply' => 'clientaction=freespin&credit=10060&totalwin.coins=40&totalwin.cents=80&nextaction=spin'],
            ]]);

        $spin = $this->servlet($session, 'action=spin&bet.betlevel=3&bet.denomination=5&bet.betlines=0');   // 10 × 3 × 5¢ = 1.50
        $this->assertSame((string) (10000 - 150), $spin['credit']);       // recorded −20¢ at 2¢ → −10 coins × 3 × 5¢
        $last = $this->servlet($session, 'action=freespin');
        $this->assertSame('120', $last['totalwin.coins']);                  // 40 coins × level 3
        $this->assertSame('600', $last['totalwin.cents']);                  // 80¢ / 2 × 3 × 5
        $this->assertEqualsWithDelta(100 - 1.5 + 6, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_a_game_whose_round_opens_with_its_own_action_takes_the_stake_there(): void
    {
        // Swipe & Roll: `pickwintype` carries the bet, `spin` settles the round
        [, $player, $session] = $this->netentGame(['game_id' => 'swipe_and_roll', 'bet_coins' => 20]);
        NetEntRound::create(['game_key' => 'swipe_and_roll', 'bet_coins' => 20, 'win_coins' => 51, 'start_credit' => 10000, 'created_at' => now(),
            'steps' => [
                ['sent' => 'pickwintype', 'reply' => 'clientaction=pickwintype&credit=9980&nextaction=spin'],
                ['sent' => 'spin', 'reply' => 'clientaction=spin&credit=10031&totalwin.coins=51&totalwin.cents=51&nextaction=pickwintype'],
            ]]);

        $pick = $this->servlet($session, 'action=pickwintype&bet.betlevel=1&bet.denomination=1&bet.betlines=0-19');
        $this->assertSame('spin', $pick['nextaction']);
        $this->assertEqualsWithDelta(100 - 0.2 + 0.51, (float) $player->wallet->fresh()->balance, 0.001);
        $this->assertSame('pickwintype', $this->servlet($session, 'action=spin')['nextaction']);
        $this->assertSame('pickwintype', $this->servlet($session, 'action=reloadbalance')['nextaction']);
    }

    public function test_a_ways_game_stakes_its_measured_coins_not_its_line_count(): void
    {
        // a 243-ways game costing 25 coins a spin
        [, $player, $session] = $this->netentGame(['lines' => 243, 'bet_coins' => 25]);
        // of the bet options (0.30, 0.60, 0.90, 1.50) only 1.50 = 25 × level 3 × 2¢ fits
        $this->assertSame('2', $this->servlet($session, 'action=init')['denomination.all']);
        NetEntRound::create(['game_key' => 'test_game', 'bet_coins' => 25, 'win_coins' => 0, 'start_credit' => 10000,
            'created_at' => now(), 'steps' => [['sent' => 'spin', 'reply' => 'clientaction=spin&credit=9975&totalwin.coins=0&nextaction=spin&gameover=true']]]);

        $this->servlet($session, 'action=spin&bet.betlevel=3&bet.denomination=2&bet.betlines=0');   // 25 × 3 × 2¢
        $this->assertEqualsWithDelta(100 - 1.5, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_init_shows_our_balance_currency_and_the_coin_values_our_bet_options_allow(): void
    {
        [, , $session] = $this->netentGame();
        $f = $this->servlet($session, 'action=init');

        $this->assertSame('10000', $f['credit']);                       // 100.00 EUR
        $this->assertSame('EUR', $f['playercurrencyiso']);
        $this->assertSame('1,2,5', $f['denomination.all']);              // 30 × level × {1,2,5}¢ hits the bet options; 10¢+ don't
        $this->assertSame('../../gameclient_html/devicedetection/current', $f['staticsharedurl']);
        $this->assertSame('1', $f['bl.i0.coins']);                       // line definitions untouched
    }

    public function test_a_free_spins_round_is_rescaled_paid_once_and_ends_on_our_balance(): void
    {
        [, $player, $session] = $this->netentGame();
        // recorded at level 1 / 1¢ from a 10000-cent demo balance: 30 staked, 65 base win, 990 in free spins
        $round = NetEntRound::create(['game_key' => 'test_game', 'bet_coins' => 30, 'win_coins' => 1055, 'feature' => true,
            'start_credit' => 10000, 'created_at' => now(), 'steps' => [
                ['sent' => 'spin', 'reply' => 'clientaction=spin&credit=10035&totalwin.coins=65&totalwin.cents=65&ws.i0.types.i0.coins=65&ws.i0.types.i0.cents=65&freespins.betlevel=1&freespins.denomination=1&bet.betlevel=1&nextaction=freespin&gameover=false&playercurrencyiso=NAN'],
                ['sent' => 'freespin', 'reply' => 'clientaction=freespin&credit=10035&freespins.win.coins=0&freespins.win.cents=0&totalwin.coins=65&totalwin.cents=65&nextaction=freespin&gameover=false'],
                ['sent' => 'freespin', 'reply' => 'clientaction=freespin&credit=11025&freespins.win.coins=990&freespins.win.cents=990&freespins.totalwin.coins=990&freespins.totalwin.cents=990&totalwin.coins=1055&totalwin.cents=1055&nextaction=spin&gameover=true'],
            ]]);
        Cache::put('netent:force:test_game', $round->id, 300);

        $this->servlet($session, 'action=init');
        $spin = $this->servlet($session, 'action=spin&bet.betlevel=2&bet.denomination=5&bet.betlines=0-29');   // stake 3.00

        $this->assertSame('130', $spin['totalwin.coins']);                // 65 × level 2
        $this->assertSame('650', $spin['totalwin.cents']);                // 65 × 2 × 5¢
        $this->assertSame('2', $spin['freespins.betlevel']);
        $this->assertSame('5', $spin['freespins.denomination']);
        $this->assertSame('EUR', $spin['playercurrencyiso']);
        $this->assertSame((string) (10000 + 35 * 10), $spin['credit']);   // our 100.00 + recorded +35 × 10
        // the whole round's win (1055 × 10 cents = 105.50) is paid with the spin
        $this->assertEqualsWithDelta(100 - 3 + 105.5, (float) $player->wallet->fresh()->balance, 0.001);

        $this->assertSame('10350', $this->servlet($session, 'action=freespin')['credit']);
        $last = $this->servlet($session, 'action=freespin');
        $this->assertSame('9900', $last['freespins.totalwin.cents']);
        $this->assertSame('20250', $last['credit']);                      // our real balance: 202.50
        $this->assertSame('spin', $last['nextaction']);

        // the round is over — another freespin is refused, not replayed
        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=freespin'));
    }

    public function test_a_bet_outside_netents_levels_or_beyond_the_balance_is_refused(): void
    {
        [, $player, $session] = $this->netentGame();
        NetEntRound::create(['game_key' => 'test_game', 'bet_coins' => 30, 'win_coins' => 0, 'start_credit' => 10000,
            'created_at' => now(), 'steps' => [['sent' => 'spin', 'reply' => 'clientaction=spin&credit=9970&nextaction=spin&gameover=true']]]);

        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=spin&bet.betlevel=9&bet.denomination=1&bet.betlines=0-29'));
        $player->wallet->update(['balance' => 0.2]);
        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=spin&bet.betlevel=1&bet.denomination=1&bet.betlines=0-29'));
        $this->assertEqualsWithDelta(0.2, (float) $player->wallet->fresh()->balance, 0.001);
    }
}
