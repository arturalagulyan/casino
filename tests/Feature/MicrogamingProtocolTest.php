<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\MicrogamingRound;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Microgaming\MicrogamingLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Microgaming replies are recorded at one coin of the smallest chip and served
 * at the player's chip × coins: cent attributes × chip·coins / recorded chip,
 * coin counts × coins, the balance ours — the round's win paid once, at the
 * paid Spin; the BonusPick / FreeSpin packets that follow are its next steps.
 */
class MicrogamingProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const string INIT = '<Pkt><Id mid="1" cid="2" sid="3" sessionid="sessionid" verb="Refresh"/><Response><Framework state="0"/>'
        .'<Player balance="10000" totalWin="0" type="5" currency="0"/><Slot win="0" state="0"><VisArea numRows="3" numCols="5"/>'
        .'<NextSpin nextNumChips="1" nextChipSize="1"/></Slot><Bet numChips="1" chipSize="1" activePaylines="0" maxChips="10" minChips="1"'
        .' validChips="1,2,5,10" slotBetMethod="1"><Paylines><Payline payline="0" paylineCost="30"/></Paylines></Bet></Response></Pkt>';

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function microgamingGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestMicrogaming', 'title' => 'Test Microgaming', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Microgaming, 'default_denomination' => 1,
            'bonus_config' => ['microgaming' => ['game_key' => 'test_mg_html', 'record_chip' => 1, 'chips' => [1, 2, 5, 10], 'languages' => ['en'],
                'init' => self::INIT, 'account' => ['core' => ['currencyIsoCode' => 'NAN', 'userTypeId' => 5], 'balance' => []],
                'titan_config' => 'window.titanConfig = {"plugins":{"veyron":{"veyronBaseUrl":"{{MG_SESSION}}/engine?"}}};']],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.3, 0.6, 1.5], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'mg'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    private function packet(GameSession $s, string $request): string
    {
        return (string) $this->postJson("/microgaming/{$s->token}/engine?/games/module/1/client/2/play", ['packet' => ['packetType' => 7,
            'payload' => '<Pkt version="5"><Id mid="1" cid="2" sid="3" verb="AdvSlot"/>'.$request.'</Pkt>', 'useFilter' => true], 'context' => []])
            ->assertOk()->json('packet.payload');
    }

    public function test_the_mobile_shells_bare_xml_packets_are_answered_in_kind(): void
    {
        // Gods of Giza: bare XML to engine?&a=<time> — AdvSlot is played, the rest replayed as recorded
        [$game, , $s] = $this->microgamingGame();
        $tpl = $game->template;
        $cfg = $tpl->bonus_config;
        $cfg['microgaming']['session'] = ['xml:GenericAuthLogin' => ['status' => 200, 'type' => 'text/xml',
            'body' => '<Pkt><Id verb="GenericAuthLogin"/><Response><Player balance="10000"/></Response></Pkt>', 'location' => '']];
        $tpl->update(['bonus_config' => $cfg]);
        $post = fn (string $xml) => $this->call('POST', "/microgaming/{$s->token}/engine?&a=1795712505918", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $xml);

        $refresh = $post("<Pkt version='5'><Id mid='10422' cid='40300' sid='22619' sessionid='sessionid' verb='AdvSlot' clientLang='en'/><Request verbex='Refresh'/></Pkt>")->assertOk();
        $this->assertStringStartsWith('text/xml', (string) $refresh->headers->get('Content-Type'));
        $this->assertSame('10000', MicrogamingLiveClient::attr($refresh->getContent(), 'Player', 'balance'));
        $this->assertSame('Refresh', MicrogamingLiveClient::attr($refresh->getContent(), 'Id', 'verb'));

        $this->assertStringContainsString('verb="GenericAuthLogin"', $post("<Pkt><Id mid='1' cid='40001' sid='22619' verb='GenericAuthLogin'/><Request/></Pkt>")->getContent());
        $this->assertSame('<Pkt><Id verb="VP_GETNUMMESSAGES"/><Response/></Pkt>', $post("<Pkt><Id mid='130' cid='0' verb='VP_GETNUMMESSAGES'/><Request/></Pkt>")->getContent());
    }

    public function test_login_and_config_name_our_player_and_session(): void
    {
        [, , $s] = $this->microgamingGame();
        $login = $this->postJson("/microgaming/{$s->token}/engine?/accounts/login/token", [])->assertOk()->json();
        $this->assertSame('EUR', $login['account']['core']['currencyIsoCode']);
        $this->assertEquals(100, $login['account']['balance']['totalInAccountCurrency']);
        $this->assertSame($s->token, json_decode(base64_decode(strtr(explode('.', $login['tokens']['userToken'])[1], '-_', '+/')), true)['sub']);
        $this->get("/microgaming/{$s->token}/config.js")->assertOk()->assertSee(url("/microgaming/{$s->token}/engine?"), false);
    }

    public function test_refresh_carries_our_balance_and_chips(): void
    {
        [, , $s] = $this->microgamingGame();
        $r = $this->packet($s, "<Request verbex='Refresh'/>");
        $this->assertSame('10000', MicrogamingLiveClient::attr($r, 'Player', 'balance'));
        $this->assertSame('1,2,5', MicrogamingLiveClient::attr($r, 'Bet', 'validChips'));   // 10¢ → a 3.00 stake the game doesn't offer
        $this->assertSame('Refresh', MicrogamingLiveClient::attr($r, 'Id', 'verb'));
    }

    public function test_a_feature_round_is_paid_at_the_spin_and_served_scaled(): void
    {
        [, $player, $s] = $this->microgamingGame();
        // recorded at chip 1 (stake 0.30) from 100.00: the spin wins 0.60 and triggers a pick, a free spin wins 1.50
        $round = MicrogamingRound::create(['game_key' => 'test_mg_html', 'bet_cents' => 30, 'win_cents' => 210, 'feature' => true,
            'start_credit' => 10000, 'steps' => [
                ['q' => 'Spin', 'p' => [], 'r' => '<Pkt><Id verb="Spin"/><Response><Player balance="10030" totalWin="60"/><Slot win="60" state="1"><Wins><Win numCoinsWon="60"/></Wins>'
                    .'<NextSpin nextNumChips="1" nextChipSize="1" freeSpinsRemaining="1"/></Slot><BonusGames><Bonus id="0" state="1" win="0"/></BonusGames></Response></Pkt>'],
                ['q' => 'BonusPick', 'p' => ['item' => '0'], 'r' => '<Pkt><Id verb="BonusPick"/><Response><Player balance="10030"/><Slot win="60" state="1"><NextSpin freeSpinsRemaining="1"/></Slot></Response></Pkt>'],
                ['q' => 'FreeSpin', 'p' => [], 'r' => '<Pkt><Id verb="FreeSpin"/><Response><Player balance="10180" totalWin="210"/><Slot win="150" state="3"><Wins><Win numCoinsWon="150"/></Wins><NextSpin freeSpinsRemaining="0"/></Slot></Response></Pkt>'],
            ]]);
        Cache::put('microgaming:force:test_mg_html', $round->id, 60);

        // chip 2 × 2 coins: stake 1.20, everything × 4 (coins × 2)
        $spin = $this->packet($s, "<Request verbex='Spin' chipSize='2' numChips='2' activePaylines='0' offerId='0' instanceId='0'/>");
        $this->assertSame('240', MicrogamingLiveClient::attr($spin, 'Slot', 'win'));
        $this->assertSame('120', MicrogamingLiveClient::attr($spin, 'Win', 'numCoinsWon'));
        $this->assertSame('2', MicrogamingLiveClient::attr($spin, 'NextSpin', 'nextChipSize'));
        $this->assertSame('10120', MicrogamingLiveClient::attr($spin, 'Player', 'balance'));   // before the stake + the recorded change (+0.30) × 4
        $this->assertEquals(100 - 1.2 + 8.4, (float) $player->wallet->fresh()->balance);   // the whole win, paid at the spin

        $pick = $this->packet($s, "<Request verbex='BonusPick' item='0'/>");
        $this->assertSame('BonusPick', MicrogamingLiveClient::attr($pick, 'Id', 'verb'));
        $this->assertSame('10120', MicrogamingLiveClient::attr($pick, 'Player', 'balance'));

        $free = $this->packet($s, "<Request verbex='FreeSpin'/>");
        $this->assertSame('600', MicrogamingLiveClient::attr($free, 'Slot', 'win'));
        $this->assertSame('10720', MicrogamingLiveClient::attr($free, 'Player', 'balance'));   // the last step: our real balance

        // round over: the next Spin is a new paid round
        Cache::put('microgaming:force:test_mg_html', $round->id, 60);
        $this->packet($s, "<Request verbex='Spin' chipSize='1' numChips='1' activePaylines='0' offerId='0' instanceId='0'/>");
        $this->assertEquals(107.2 - 0.3 + 2.1, (float) $player->wallet->fresh()->balance);
    }

    public function test_a_reload_inside_a_round_gets_the_setups_bet_block(): void
    {
        [, , $s] = $this->microgamingGame();
        // Book of Mega Moolah: a step reply has no <Bet> — resumed in its free spins, the client built no reel window
        $round = MicrogamingRound::create(['game_key' => 'test_mg_html', 'bet_cents' => 30, 'win_cents' => 60, 'feature' => true,
            'start_credit' => 10000, 'steps' => [
                ['q' => 'Spin', 'p' => [], 'r' => '<Pkt><Id verb="Spin"/><Response><Player balance="10030" totalWin="60"/><Slot win="60" state="1"><VisArea numRows="3" numCols="5"/>'
                    .'<NextSpin nextNumChips="1" nextChipSize="1" freeSpinsRemaining="1"/></Slot></Response></Pkt>'],
                ['q' => 'FreeSpin', 'p' => [], 'r' => '<Pkt><Id verb="FreeSpin"/><Response><Player balance="10030" totalWin="60"/><Slot win="0" state="3"><NextSpin freeSpinsRemaining="0"/></Slot></Response></Pkt>'],
            ]]);
        Cache::put('microgaming:force:test_mg_html', $round->id, 60);
        $this->packet($s, "<Request verbex='Spin' chipSize='2' numChips='1' activePaylines='0' offerId='0' instanceId='0'/>");

        $refresh = $this->packet($s, "<Request verbex='Refresh'/>");
        $this->assertSame('Refresh', MicrogamingLiveClient::attr($refresh, 'Id', 'verb'));
        $this->assertSame('1', MicrogamingLiveClient::attr($refresh, 'Slot', 'state'));   // still the step last served
        $this->assertSame(1, substr_count($refresh, '<Bet '));
        $this->assertStringContainsString('</Slot><Bet ', $refresh);
        $this->assertSame('2', MicrogamingLiveClient::attr($refresh, 'Bet', 'chipSize'));
        $this->assertSame('1,2,5', MicrogamingLiveClient::attr($refresh, 'Bet', 'validChips'));   // our chips (bet options), as setup() offers
        $this->assertStringContainsString('<Payline payline="0" paylineCost="30"/>', $refresh);

        // and the round carries on where it was
        $this->assertSame('3', MicrogamingLiveClient::attr($this->packet($s, "<Request verbex='FreeSpin'/>"), 'Slot', 'state'));
    }

    public function test_a_different_pick_switches_to_a_round_where_it_was_made(): void
    {
        [, $player, $s] = $this->microgamingGame();
        $pick = fn (string $item, int $bal, int $fsWin) => [
            ['q' => 'Spin', 'p' => [], 'r' => '<Pkt><Id verb="Spin"/><Response><Player balance="9970"/><Slot win="0" state="1"><NextSpin freeSpinsRemaining="1"/></Slot>'
                .'<BonusGames><Bonus id="0" state="1" items="0,1"/></BonusGames></Response></Pkt>'],
            ['q' => 'BonusPick', 'p' => ['item' => $item], 'r' => '<Pkt><Id verb="BonusPick"/><Response><Player balance="9970"/><Slot win="0"><NextSpin freeSpinsRemaining="1"/></Slot></Response></Pkt>'],
            ['q' => 'FreeSpin', 'p' => [], 'r' => '<Pkt><Id verb="FreeSpin"/><Response><Player balance="'.$bal.'"/><Slot win="'.$fsWin.'" state="3"><NextSpin freeSpinsRemaining="0"/></Slot></Response></Pkt>'],
        ];
        $valkyrie = MicrogamingRound::create(['game_key' => 'test_mg_html', 'bet_cents' => 30, 'win_cents' => 100, 'feature' => true, 'start_credit' => 10000, 'steps' => $pick('0', 10070, 100)]);
        MicrogamingRound::create(['game_key' => 'test_mg_html', 'bet_cents' => 30, 'win_cents' => 500, 'feature' => true, 'start_credit' => 10000, 'steps' => $pick('1', 10470, 500)]);
        Cache::put('microgaming:force:test_mg_html', $valkyrie->id, 60);

        $this->packet($s, "<Request verbex='Spin' chipSize='1' numChips='1' activePaylines='0' offerId='0' instanceId='0'/>");
        $this->assertEquals(100 - 0.3 + 1.0, (float) $player->wallet->fresh()->balance);
        $this->packet($s, "<Request verbex='BonusPick' item='1'/>");   // not the recorded 0
        $free = $this->packet($s, "<Request verbex='FreeSpin'/>");
        $this->assertSame('500', MicrogamingLiveClient::attr($free, 'Slot', 'win'));
        $this->assertEquals(100 - 0.3 + 5.0, (float) $player->wallet->fresh()->balance);   // the difference paid
        $this->assertSame('10470', MicrogamingLiveClient::attr($free, 'Player', 'balance'));
    }

    public function test_a_quantum_round_is_served_scaled(): void
    {
        [$game, $player, $s] = $this->microgamingGame();
        $q = fn (array $j) => '<Pkt><Id verb="X"></Id><Response><Q version="1">'.json_encode($j).'</Q></Response></Pkt>';
        $bet = ['Type' => 'SlotBet', 'Lines' => 20, 'Level' => 1, 'Denom' => 1, 'Wager' => 20];
        $init = $q(['RequestType' => 'Refresh', 'User' => ['Balance' => 10000], 'Settings' => ['Level' => ['Min' => 1, 'Max' => 9, 'Default' => 1], 'Denom' => ['Default' => 1, 'Values' => [1, 2, 5]]],
            'QuantumResponse' => ['Stack' => ['Prev' => 0, 'Next' => [1], 'Features' => [['Type' => 'SlotGame', 'Name' => 'BaseGame', 'Index' => 0, 'Win' => 0, 'Blocks' => [$bet]], ['Type' => 'SlotGame', 'Name' => 'BaseGame', 'Index' => 1]]]]]);
        $game->template->update(['bonus_config' => ['microgaming' => array_merge($game->template->bonus_config['microgaming'], ['init' => $init])]]);
        $game->update(['bet_options' => [0.2, 0.4, 1.0]]);
        $round = MicrogamingRound::create(['game_key' => 'test_mg_html', 'bet_cents' => 20, 'win_cents' => 50, 'feature' => true, 'start_credit' => 10000, 'steps' => [
            ['q' => 'Spin', 'p' => [], 'r' => $q(['User' => ['Balance' => 9980], 'QuantumResponse' => ['Stack' => ['Next' => [1], 'Features' => [
                ['Type' => 'SlotGame', 'Name' => 'BaseGame', 'Index' => 0, 'Win' => 0, 'Blocks' => [$bet]], ['Type' => 'SlotGame', 'Name' => 'FreeSpins', 'Index' => 1, 'Win' => 0]]]]])],
            ['q' => 'FreeSpin', 'p' => [], 'r' => $q(['User' => ['Balance' => 10030], 'QuantumResponse' => ['Stack' => ['Next' => [2], 'Features' => [
                ['Type' => 'SlotGame', 'Name' => 'FreeSpins', 'Index' => 1, 'Win' => 50, 'FeatureWin' => 50, 'Blocks' => [['Type' => 'LinesRule', 'Lines' => [['Id' => 3, 'Win' => 50]]]]]]]]])],
        ]]);
        Cache::put('microgaming:force:test_mg_html', $round->id, 60);
        $cdata = fn (array $r) => "<Request verbex='{$r['Request']}'><Pkt><![CDATA[".json_encode(['QuantumRequest' => $r]).']]></Pkt></Request>';

        $refresh = MicrogamingLiveClient::quantum($this->packet($s, $cdata(['Request' => 'Refresh'])));
        $this->assertSame(10000, $refresh['User']['Balance']);
        $this->assertSame([1, 2, 5], $refresh['Settings']['Denom']['Values']);

        // denomination 2 × level 2: stake 0.80, everything × 4
        $spin = MicrogamingLiveClient::quantum($this->packet($s, $cdata(['Request' => 'Spin', 'Feature' => 'BaseGame', 'BetLevel' => 2, 'Denomination' => 2, 'Wager' => 80, 'Lines' => 20])));
        $this->assertSame(2, $spin['QuantumResponse']['Stack']['Features'][0]['Blocks'][0]['Denom']);
        $this->assertSame(80, $spin['QuantumResponse']['Stack']['Features'][0]['Blocks'][0]['Wager']);
        $this->assertEquals(100 - 0.8 + 2.0, (float) $player->wallet->fresh()->balance);
        $free = MicrogamingLiveClient::quantum($this->packet($s, $cdata(['Request' => 'FreeSpin', 'Feature' => 'FreeSpins'])));
        $this->assertSame(200, $free['QuantumResponse']['Stack']['Features'][0]['Lines'] ?? $free['QuantumResponse']['Stack']['Features'][0]['Blocks'][0]['Lines'][0]['Win']);
        $this->assertSame(10120, $free['User']['Balance']);
    }

    public function test_a_bet_outside_the_chips_is_refused(): void
    {
        [, $player, $s] = $this->microgamingGame();
        $r = $this->packet($s, "<Request verbex='Spin' chipSize='10' numChips='1' activePaylines='0' offerId='0' instanceId='0'/>");
        $this->assertStringContainsString('<Error', $r);
        $this->assertEquals(100, (float) $player->wallet->fresh()->balance);
    }
}
