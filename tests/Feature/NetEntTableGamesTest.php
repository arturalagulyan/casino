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
use App\Services\GamePlay\NetEnt\Table\NetEntBaccarat;
use App\Services\GamePlay\NetEnt\Table\NetEntBlackjack;
use App\Services\GamePlay\NetEnt\Table\NetEntRoulette;
use App\Services\GamePlay\NetEnt\Table\NetEntVideoPoker;
use App\Services\GamePlay\NetEnt\Table\RouletteLayout;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NetEnt's table games, served by our own engines. The cases are what
 * NetEnt's own servers did: the numbers they paid, the hands they dealt.
 */
class NetEntTableGamesTest extends TestCase
{
    use RefreshDatabase;

    private const string FRENCH_INIT = '&autoplay=10,25&column=120000&corner=40000&credit=10000&defaultdenomination=10&denominations=10,50,100,200,500&dozen=120000&gamesoundurl=https://static.example/netEnt&highlow=200000&maxbet=500000&minbet=10&oddeven=200000&playercurrency= &playercurrencyiso=NAN&playforfun=false&redblack=200000&sixline=60000&split=20000&straight=10000&threeline=30000';

    private const string TOUCH_INIT = '&credit=10000&defaultdenomination=1&denominations=1,5,10,50,100,200,500&maxbet.column=200&maxbet.corner=40&maxbet.dozen=200&maxbet.highlow=500&maxbet.oddeven=500&maxbet.redblack=500&maxbet.sixline=60&maxbet.split=20&maxbet.straight=10&maxbet.threeline=30&maxbet=500&minbet=1&playercurrency= &playercurrencyiso=NAN&playforfun=false';

    /** @return list<string> the positions among $keys that cover $n */
    private function winners(RouletteLayout $layout, array $keys, int $n): array
    {
        return array_values(array_filter($keys, fn ($k) => in_array($n, $layout->position($k)['numbers'] ?? [], true)));
    }

    public function test_european_positions_cover_what_netent_paid(): void
    {
        $eu = new RouletteLayout;
        $keys = ['red', 'black', 'odd', 'even', 'low', 'high', 'dozen.1', 'dozen.2', 'dozen.3', 'column.1', 'column.2', 'column.3', '34', '7'];
        $this->assertSame(['red', 'even', 'high', 'dozen.3', 'column.1', '34'], $this->winners($eu, $keys, 34));
        $this->assertSame(['red', 'odd', 'low', 'dozen.1', 'column.1', '7'], $this->winners($eu, $keys, 7));

        $this->assertSame([5, 8], $eu->position('8.3')['numbers']);           // split, as the client draws it
        $this->assertSame([10, 11], $eu->position('11.1')['numbers']);
        $this->assertSame([1, 2, 3], $eu->position('1.1')['numbers']);         // street
        $this->assertSame([0, 2, 3], $eu->position('3.2')['numbers']);         // trio
        $this->assertSame([1, 2, 3, 4, 5, 6], $eu->position('4.2')['numbers']);   // six-line
        $this->assertSame([1, 2, 4, 5], $eu->position('5.2')['numbers']);      // corner
        $this->assertSame([36, 18, 12, 9, 6, 3, 2], array_map(fn ($k) => $eu->position($k)['returns'], ['17', '8.3', '1.1', '5.2', '4.2', 'dozen.1', 'red']));
        $this->assertNull($eu->position('00'));
        $this->assertNull($eu->position('37'));
    }

    public function test_american_zero_positions_cover_what_netent_paid(): void
    {
        $us = new RouletteLayout(american: true);
        $keys = ['0', '00', '00.1', '2.4', '1.2', '1.3', '2.2', '3.3', '3.2'];
        $this->assertSame(['0', '00.1', '2.4', '1.2', '1.3', '2.2'], $this->winners($us, $keys, 0));
        $this->assertSame(['00', '00.1', '2.4', '1.2', '3.3', '3.2'], $this->winners($us, $keys, RouletteLayout::DOUBLE_ZERO));
        $this->assertSame(7, $us->position('1.2')['returns']);   // the top line pays 6:1
        $this->assertNull($us->position('2.3'));                  // NetEnt's server takes no stake for it
    }

    /**
     * A table game in a shop, the engine bound as given (a fixed wheel, shoe…).
     *
     * @return array{0: User, 1: GameSession}
     */
    private function table(string $gameId, string $init, int|object $engine, bool $demo = false): array
    {
        is_int($engine) ? app()->instance(NetEntRoulette::class, new NetEntRoulette(fn () => $engine)) : app()->instance($engine::class, $engine);
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'N', 'slug' => 'n', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'table_bank' => 100000, 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestRoulette', 'title' => 'Test Roulette', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'table',
            'client_protocol' => ClientProtocol::NetEnt, 'default_denomination' => 1,
            'bonus_config' => ['netent' => ['game_id' => $gameId, 'init' => $init, 'languages' => ['en']]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'table', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.1, 0.5, 1, 2, 5], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        if ($demo) {
            $player->forceFill(['free_demo' => true])->save();
        }

        return [$player, GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'rt'.uniqid(), 'is_active' => true])];
    }

    private string $body = '';

    /** @return array<string, string> */
    private function info(GameSession $s, string $query): string
    {
        return (string) $this->get("/netent/{$s->token}/servlet/InfoServlet;jsession={$s->token}?{$query}&sessid={$s->token}")->assertOk()->getContent();
    }

    /** @return array<string, string> */
    private function servlet(GameSession $s, string $query): array
    {
        $body = $this->body = (string) $this->get("/netent/{$s->token}/servlet/CasinoGameServlet;jsession={$s->token}?{$query}&sessid={$s->token}")->assertOk()->getContent();

        return NetEntRound::fields($body);
    }

    public function test_a_french_spin_pays_the_winning_chips_and_reports_the_number(): void
    {
        [$player, $session] = $this->table('french_roulette_html', self::FRENCH_INIT, 17);
        $this->assertSame('10000', $this->servlet($session, 'action=init')['credit']);

        // the chips the real client sent in our capture: 17, red, split 5/8, top column
        $r = $this->servlet($session, 'action=spin&playercurrencyiso=EUR&bet.17=10&bet.red=10&bet.8.3=10&bet.column.3=10');
        $this->assertStringStartsWith('&credit=', $this->body);   // roulette replies open with `&`, as NetEnt's
        $this->assertSame('17', $r['winnumber']);
        $this->assertSame('3.6', $r['win.17']);
        $this->assertArrayNotHasKey('win.red', $r);                  // 17 is black: only winners are listed
        $this->assertSame('10320', $r['credit']);                   // 100 − 0.40 + 3.60
        $this->assertEqualsWithDelta(103.2, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_a_position_the_table_lacks_costs_nothing_and_limits_are_enforced(): void
    {
        [$player, $session] = $this->table('american_roulette_html', self::FRENCH_INIT, 2);
        $r = $this->servlet($session, 'action=spin&bet.2.3=10');
        $this->assertSame('10000', $r['credit']);
        $this->assertEqualsWithDelta(100, (float) $player->wallet->fresh()->balance, 0.001);

        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=spin&bet.17=10001'));   // straight limit 100.00
        $this->assertEqualsWithDelta(100, (float) $player->wallet->fresh()->balance, 0.001);

        $r = $this->servlet($session, 'action=spin&bet.00=10&bet.2=10');   // 00 is a position on this table
        $this->assertSame('3.6', $r['win.2']);
        $this->assertSame('10340', $r['credit']);                   // 100 − 0.20 + 3.60
    }

    public function test_roulette_touch_draws_with_netents_full_reply(): void
    {
        [$player, $session] = $this->table('roulette_touch', self::TOUCH_INIT, 21);
        $r = $this->servlet($session, 'action=draw&playercurrencyiso=EUR&bet.21=1&bet.red=1&bet.dozen.1=1&selectedchip=1');
        $this->assertSame(['3', '38', '0.36', '0.02', '0'], [$r['totbet'], $r['totwin'], $r['win.21'], $r['win.red'], $r['win.dozen.1']]);
        $this->assertSame(['21', 'red', 'high', 'even'], [$r['winnumber'], $r['wincolor'], $r['winhighlow'], $r['winoddeven']]);   // parity flipped, as NetEnt's
        $this->assertEqualsWithDelta(100 - 0.03 + 0.38, (float) $player->wallet->fresh()->balance, 0.001);

        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=draw&bet.21=11'));   // Touch: 0.10 a straight
    }

    private const string BACCARAT_INIT = 'clientaction=init&confirmBetMessageEnabled=false&credit=10000&denomination.all=1,5,10,50,100,200,500&denomination.default=1&gameover=true&max_bet=1000&min_bet=1&playercurrency= &playercurrencyiso=NAN&playforfun=false&restore=false';

    public function test_baccarat_draws_third_cards_as_netent_dealt_them(): void
    {
        // hands from NetEnt's server: [player, banker], in our dealing order P P B B, P3, B3
        foreach ([
            [['3S', '9H', '9H'], ['2H', 'JD', '4D']],   // player 2 draws; banker 2 draws
            [['7S', '8C', 'AS'], ['6C', 'KH']],         // player 5 draws an ace; banker 6 stands on a third 1
            [['AD', 'TC'], ['KS', '9H']],               // banker natural 9: nobody draws
        ] as [$p, $b]) {
            $shoe = [$p[0], $p[1], $b[0], $b[1], ...array_slice($p, 2), ...array_slice($b, 2), '2C', '2C'];
            $this->assertSame([$p, $b], (new NetEntBaccarat(fn () => $shoe))->hands());
        }
    }

    public function test_a_baccarat_deal_pays_as_netent_with_the_commission_cents_floored(): void
    {
        // banker 9 beats player 1: a 1¢ banker bet returns 1.95¢, of which NetEnt pays 1
        [$player, $session] = $this->table('baccarat', self::BACCARAT_INIT, new NetEntBaccarat(fn () => ['AD', 'TC', 'KS', '9H']));
        $r = $this->servlet($session, 'action=deal&playercurrencyiso=EUR&tiebet=1&bankerbet=1&playerbet=1&selectedchip=1');
        $this->assertSame(['AD,TC', '1', 'KS,9H', '9'], [$r['playerhand.hand'], $r['playerhand.value'], $r['bankerhand.hand'], $r['bankerhand.value']]);
        $this->assertStringContainsString('playerhand.hand=AD,TC&', $this->body);   // commas raw, as NetEnt: its clients decodeURI, which keeps %2C
        $this->assertSame(['0', '1.95', '0'], [$r['playerwin.cents'], $r['bankerwin.cents'], $r['tiewin.cents']]);
        $this->assertSame('9998', $r['credit']);   // 10000 − 3 + 1
        $this->assertEqualsWithDelta(99.98, (float) $player->wallet->fresh()->balance, 0.0001);

        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=deal&playerbet=1001'));   // max_bet 10.00
    }

    public function test_a_baccarat_tie_returns_player_and_banker_bets(): void
    {
        [$player, $session] = $this->table('baccarat', self::BACCARAT_INIT, new NetEntBaccarat(fn () => ['7S', '8C', '6C', 'KH', 'AS', '2C']));
        $r = $this->servlet($session, 'action=deal&tiebet=1&bankerbet=1&playerbet=1');
        $this->assertSame(['1', '1', '9', '10008'], [$r['playerwin.cents'], $r['bankerwin.cents'], $r['tiewin.cents'], $r['credit']]);
    }

    private const string POKER_INIT = 'availableHands=1,5,10,25&credit=10000&defaultdenomination.h1=4&denominations.h1=4,5,10,25&defaultdenomination.h5=3&denominations.h5=3,4,5&maxcoins.h1=5&mincoins.h1=1&maxcoins.h5=5&mincoins.h5=1&state=DEALING&playercurrency= &playercurrencyiso=NAN&playforfun=false&pt.0.0=1&pt.0.4=5&pt.1.0=2&pt.2.0=3&pt.3.0=5&pt.4.0=6&pt.5.0=9&pt.6.0=20&pt.7.0=40&pt.8.0=250&pt.8.4=4000';

    public function test_video_poker_ranks_hands_as_netent(): void
    {
        $e = new NetEntVideoPoker;
        foreach ([
            ['TSJSQSKSAS', 'ROYAL_STRAIGHT_FLUSH', 'TTTTT'], ['AH2H3H4H5H', 'STRAIGHT_FLUSH', 'TTTTT'],
            ['9C9D9H9S2C', 'FOUR_OF_A_KIND', 'TTTTF'], ['KSKHKD4C4D', 'FULL_HOUSE', 'TTTTT'],
            ['2D7D9DJDKD', 'FLUSH', 'TTTTT'], ['AS2D3C4H5S', 'STRAIGHT', 'TTTTT'],
            // from NetEnt's server
            ['8DQSTSTCTH', 'THREE_OF_A_KIND', 'FFTTT'], ['7D3S9H3C7C', 'TWO_PAIRS', 'TTFTT'], ['2HQC8DQH4D', 'JACKS_OR_BETTER', 'FTFTF'],
            ['TSTH2C3D5S', null, 'FFFFF'],   // tens: no pay
        ] as [$cards, $type, $mask]) {
            $this->assertSame([$type, $mask], $e->evaluate(str_split($cards, 2)), $cards);
        }
    }

    public function test_a_video_poker_round_pays_only_when_collected(): void
    {
        // one hand, 1 coin of 4¢: dealt Q Q, hold them, draw a third queen
        $deck = fn (int $for) => match ($for) { 0 => ['2H', 'QC', '8D', 'QH', '4D'], default => ['QS', '5C', '9H'], -1 => ['KH'] };
        [$player, $session] = $this->table('jacks_or_better_multiple_hands', self::POKER_INIT, new NetEntVideoPoker($deck));

        $d = $this->servlet($session, 'action=deal&coins=1&coinvalue=4&nhands=1');
        $this->assertSame(['2HQC8DQH4D', 'JACKS_OR_BETTER', 'FTFTF', 'DRAWING', '9996'], [$d['cards'], $d['handtype'], $d['autohold'], $d['state'], $d['credit']]);

        $r = $this->servlet($session, 'action=draw&hold=FTFTF');
        $this->assertSame(['QS5C9H', 'THREE_OF_A_KIND', '3', '12', 'AWAITING_DOUBLING_OR_COLLECT', '9996'],
            [$r['newcards.0'], $r['handtype.0'], $r['win.coins.0'], $r['inplay.cents'], $r['state'], $r['credit']]);   // not paid yet
        $this->assertEqualsWithDelta(99.96, (float) $player->wallet->fresh()->balance, 0.0001);

        $c = $this->servlet($session, 'action=doubleup&choice=COLLECT');
        $this->assertSame(['GAMEOVER', '12', '3', '10008'], [$c['state'], $c['win.cents'], $c['win.coins'], $c['credit']]);
        $this->assertEqualsWithDelta(100.08, (float) $player->wallet->fresh()->balance, 0.0001);
    }

    public function test_a_video_poker_gamble_doubles_on_the_right_colour_and_loses_it_all_on_the_wrong_one(): void
    {
        $gamble = ['KH'];
        $deck = function (int $for) use (&$gamble) {
            return match ($for) { 0 => ['2H', 'QC', '8D', 'QH', '4D'], -1 => [array_shift($gamble) ?? 'KS'], default => ['3S', '5C', '9H'] };
        };
        [$player, $session] = $this->table('jacks_or_better_multiple_hands', self::POKER_INIT, new NetEntVideoPoker($deck));
        $this->servlet($session, 'action=deal&coins=1&coinvalue=4&nhands=1');
        $this->assertSame('4', $this->servlet($session, 'action=draw&hold=FTFTF')['inplay.cents']);   // the queens: 1 coin

        $this->assertSame('PICKING', $this->servlet($session, 'action=doubleup&choice=ALL')['state']);
        $won = $this->servlet($session, 'action=pick&pick=RED');   // KH
        $this->assertSame(['KH', 'RED', 'HEARTS', '8', 'DOUBLING', '4'], [$won['card'], $won['color'], $won['suit'], $won['inplay.cents'], $won['state'], $won['maxdoubleupsleft']]);

        $this->servlet($session, 'action=doubleup&choice=ALL');
        $lost = $this->servlet($session, 'action=pick&pick=RED');   // KS
        $this->assertSame(['0', 'GAMEOVER'], [$lost['inplay.cents'], $lost['state']]);
        $this->assertEqualsWithDelta(99.96, (float) $player->wallet->fresh()->balance, 0.0001);
    }

    public function test_a_video_poker_round_left_open_is_settled_for_the_player_on_the_next_load(): void
    {
        $deck = fn (int $for) => match ($for) { 0 => ['KS', 'KH', 'KD', '4C', '4D'], default => ['2C', '3C', '5C'], -1 => ['2S'] };
        [$player, $session] = $this->table('jacks_or_better_multiple_hands', self::POKER_INIT, new NetEntVideoPoker($deck));
        $this->servlet($session, 'action=deal&coins=1&coinvalue=4&nhands=1');   // a full house, then the player leaves
        $this->servlet($session, 'action=init');                                 // kept as dealt and paid: 9 × 4¢
        $this->assertEqualsWithDelta(100 - 0.04 + 0.36, (float) $player->wallet->fresh()->balance, 0.0001);
        $this->assertSame('DRAWING', $this->servlet($session, 'action=deal&coins=1&coinvalue=4&nhands=1')['state']);   // a new round may start
    }

    /**
     * Real rounds from NetEnt's blackjack servers — the same shoe and the
     * same requests — must come back field for field as NetEnt sent them.
     *
     * @return iterable<string, array{0: string, 1: list<string>, 2: list<array{query: string, reply: string}>}>
     */
    public static function blackjackRounds(): iterable
    {
        $f = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/netent_blackjack_rounds.json'), true);
        foreach ($f['rounds'] as $r) {
            yield "{$r['game']} {$r['case']}" => [$r['game'], $f['inits'][$r['game']], $r['shoe'], $r['steps']];
        }
    }

    #[DataProvider('blackjackRounds')]
    public function test_a_blackjack_round_replies_as_netent(string $game, string $init, array $shoe, array $steps): void
    {
        [, $session] = $this->table($game, $init, new NetEntBlackjack(fn () => $shoe));
        $ignore = '/^(credit|g4mode|gamesoundurl|playercurrency|playercurrencyiso|jackpotcurrency|jackpotcurrencyiso|playforfun|historybutton|isJackpotWin)$/';
        $ours0 = $theirs0 = null;
        foreach ($steps as $i => $step) {
            $ours = $this->servlet($session, $step['query']);
            $theirs = NetEntRound::fields($step['reply']);
            $ours0 ??= (int) $ours['credit'] + ($i === 0 ? 0 : 0);
            $theirs0 ??= (int) $theirs['credit'];
            foreach ($theirs as $k => $v) {
                if (! preg_match($ignore, $k)) {
                    $this->assertSame($v, $ours[$k] ?? '(missing)', "step {$i} ({$step['query']}): {$k}");
                }
            }
            $this->assertSame((int) $theirs['credit'] - $theirs0, (int) $ours['credit'] - $ours0, "step {$i}: credit movement");
        }
    }

    public function test_a_blackjack_round_left_open_is_played_out_on_the_next_load_and_stray_insurance_costs_nothing(): void
    {
        $init = 'clientaction=init&MAX_BOX_BET=500&MIN_BOX_BET=10&denomination.all=10,20,50,100,200,500&credit=10000&playercurrencyiso=NAN&playforfun=false';
        // box 0: 9, 9 (18) against the dealer's 7 — then 10 for the dealer: 17, the player wins
        [$player, $session] = $this->table('blackjack_html', $init, new NetEntBlackjack(fn () => ['9S', '7H', '9D', 'TC', '2C', '2C']));
        $this->assertSame('inplay', $this->servlet($session, 'action=deal&bet=0:10')['box.i0.playerhand.i0.state']);
        $this->assertArrayHasKey('errorcode', $this->servlet($session, 'action=insurance'));   // no ace showing
        $this->assertEqualsWithDelta(99.9, (float) $player->wallet->fresh()->balance, 0.0001);

        $this->servlet($session, 'action=init');   // the player went away: the hand stands, 18 beats 17
        $this->assertEqualsWithDelta(100.1, (float) $player->wallet->fresh()->balance, 0.0001);
        $this->assertSame('false', $this->servlet($session, 'action=deal&bet=0:10')['gameover']);   // a new round deals
    }

    public function test_roulette_statistics_answer_on_the_info_servlet_as_netent(): void
    {
        $numbers = [17, 17, 5, 0];
        [, $session] = $this->table('french_roulette_html', self::FRENCH_INIT, new NetEntRoulette(function () use (&$numbers) { return array_shift($numbers) ?? 1; }));
        // a fresh table answers with history already (an empty `numbers=` breaks the client)
        $this->assertSame(37, count(explode(',', substr($this->info($session, 'action=stat&operation=roulettestat_extended'), 8))));
        foreach (range(1, 4) as $_) {
            $this->servlet($session, 'action=spin&bet.red=10');
        }
        $this->assertStringStartsWith('numbers=0,5,17,17,', $this->info($session, 'action=stat&operation=roulettestat_extended'));   // newest first
        $stat = NetEntRound::fields($this->info($session, 'action=stat&operation=roulettestat'));
        foreach (['hot.0.number', 'hot.4.timesdrawn', 'cold.1.number', 'cold.4.lastdrawn', 'color.green', 'oddeven.zero'] as $k) {
            $this->assertArrayHasKey($k, $stat);
        }
        $this->assertArrayNotHasKey('cold.0.number', $stat);   // NetEnt's own shape: hot.0–4, cold.1–4
    }

    public function test_american_statistics_use_their_own_operations_and_show_00_as_37(): void
    {
        [, $session] = $this->table('american_roulette_html', self::FRENCH_INIT, RouletteLayout::DOUBLE_ZERO);
        $this->servlet($session, 'action=spin&bet.00=10');
        $this->assertStringStartsWith('numbers=37', $this->info($session, 'action=stat&operation=americanroulettestat_extended'));
        $this->assertArrayHasKey('hot.0.number', NetEntRound::fields($this->info($session, 'action=stat&operation=americanroulettestat')));
        $this->assertSame('', $this->info($session, 'action=stat&operation=roulettestat'));
    }

    public function test_roulette_touch_has_no_statistics_and_an_empty_spin_is_no_round(): void
    {
        [, $session] = $this->table('roulette_touch', self::TOUCH_INIT, 7);
        $this->assertSame('', $this->info($session, 'action=stat&operation=roulettestat'));
        $this->assertSame('7', $this->servlet($session, 'action=draw&selectedchip=1')['winnumber']);   // no chips: the wheel still turns
        $this->assertSame(0, \App\Models\GameRound::query()->count());
    }

    // demo play: bankAvailable() is PHP_FLOAT_MAX — its cents once overflowed an int and failed every spin

    public function test_a_demo_player_with_no_bank_limit_can_play_roulette(): void
    {
        [, $session] = $this->table('french_roulette_html', self::FRENCH_INIT, 17, demo: true);
        $this->assertSame('3.6', $this->servlet($session, 'action=spin&bet.17=10')['win.17']);
    }

    public function test_a_demo_player_with_no_bank_limit_can_play_baccarat(): void
    {
        [, $session] = $this->table('baccarat', self::BACCARAT_INIT, new NetEntBaccarat(fn () => ['AD', 'TC', 'KS', '9H']), demo: true);
        $this->assertSame('1.95', $this->servlet($session, 'action=deal&bankerbet=1')['bankerwin.cents']);
    }

    public function test_a_demo_player_with_no_bank_limit_can_play_video_poker(): void
    {
        [, $session] = $this->table('jacks_or_better_multiple_hands', self::POKER_INIT,
            new NetEntVideoPoker(fn (int $for) => $for === 0 ? ['2H', 'QC', '8D', 'QH', '4D'] : ['3S', '5C', '9H']), demo: true);
        $this->servlet($session, 'action=deal&coins=1&coinvalue=4&nhands=1');
        $this->assertSame('4', $this->servlet($session, 'action=draw&hold=FTFTF')['inplay.cents']);
    }
}
