<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\ApolloRound;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Apollo\ApolloLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Apollo replies are recorded at the game's smallest stake and served at the
 * player's: money fields × stake / recorded stake, the balance ours — the
 * round's win paid once, at the paid spin; free spins follow, unpaid.
 */
class ApolloProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const array INIT = ['balance' => 100, 'balanceCurrency' => 'NAN', 'credit' => 100, 'creditCurrency' => 'NAN',
        'gameState' => ['stake' => 0.5, 'reelsSymbols' => [[1, 2, 3], [4, 5, 6], [7, 8, 0]], 'winning' => 0, 'numberOfBonusRounds' => 0],
        'stakeOptions' => [0.5, 1, 2.5], 'defaultBet' => 0.5, 'gameName' => 'testgame', 'name' => 'testgame_ag', 'demo' => true,
        'token' => 'sessionToken', 'tossRatePlayerWin' => 49, 'gameMathSpecificParameters' => ['winningTable' => []]];

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function apolloGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestApollo', 'title' => 'Test Apollo', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Apollo, 'default_denomination' => 1,
            'bonus_config' => ['apollo' => ['game_key' => 'testgame_ag', 'record_stake' => 0.5, 'stakes' => [0.5, 1, 2.5],
                'languages' => ['EN'], 'init' => self::INIT]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.5, 1, 2.5], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'ag'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, mixed> */
    private function act(GameSession $s, string $action, array $body = [], int $status = 200): array
    {
        return (array) $this->postJson("/apollo/{$s->token}/{$action}", $body)->assertStatus($status)->json();
    }

    private function step(float $credit, float $win, int $left, bool $inBonus = false): array
    {
        return ['balance' => 0, 'credit' => $credit, 'winval' => $win, 'gameState' => ['stake' => 0.5, 'winning' => $win,
            'winnings' => [$win, null], 'lastBonusTotalWinning' => $win, 'numberOfBonusRounds' => $left,
            'wasCurrentSpinInBonusMode' => $inBonus, 'reelsSymbols' => [[1, 1, 1], [2, 2, 2], [3, 3, 3]]], 'sequence' => 9];
    }

    public function test_init_carries_our_balance_currency_and_stakes(): void
    {
        [, , $s] = $this->apolloGame();
        $init = $this->act($s, 'casino.init', ['playerHandle' => 'x']);

        $this->assertEquals(100, $init['credit']);
        $this->assertSame('EUR', $init['creditCurrency']);
        $this->assertEquals([0.5, 1, 2.5], $init['stakeOptions']);
        $this->assertSame('testgame_ag', $init['name']);   // the client loads its game folder by this
        $this->assertFalse($init['demo']);
    }

    public function test_a_round_with_free_spins_is_paid_at_the_spin_and_served_scaled(): void
    {
        [, $player, $s] = $this->apolloGame();
        // recorded at 0.5 from credit 50: spin wins 1 and awards 2 free spins (+2, +3)
        $round = ApolloRound::create(['game_key' => 'testgame_ag', 'bet_cents' => 50, 'win_cents' => 600, 'feature' => true,
            'start_credit' => 5000, 'steps' => [$this->step(50.5, 1, 2), $this->step(52.5, 2, 1, true), $this->step(55.5, 3, 0, true)]]);
        Cache::put('apollo:force:testgame_ag', $round->id, 60);
        $this->act($s, 'casino.init');

        // at stake 1 (× 2): stake taken, whole win (12) paid at once
        $r = $this->act($s, 'casino.spin', ['stake' => 1, 'sequence' => 1]);
        $this->assertEquals(111, (float) $player->wallet->fresh()->balance);
        $this->assertEquals(2, $r['winval']);
        $this->assertEquals(1, $r['gameState']['stake']);
        $this->assertEquals([2, null], $r['gameState']['winnings']);
        $this->assertEquals(100, $r['balance']);
        $this->assertEquals(101, $r['credit']);          // 100 − 1 + 2, as the recording showed it
        $this->assertSame(2, $r['sequence']);

        $r = $this->act($s, 'casino.spin', ['stake' => 1]);   // free spin: nothing taken
        $this->assertEquals(105, $r['credit']);
        $this->assertEquals(4, $r['winval']);
        $r = $this->act($s, 'casino.spin', ['stake' => 1]);   // the last: our real balance
        $this->assertEquals(111, $r['credit']);
        $this->assertEquals(6, $r['gameState']['winning']);
        $this->assertEquals(111, (float) $player->wallet->fresh()->balance);

        // round over: the next spin is a new paid one
        Cache::put('apollo:force:testgame_ag', $round->id, 60);
        $this->act($s, 'casino.spin', ['stake' => 0.5]);
        $this->assertEquals(116.5, (float) $player->wallet->fresh()->balance);   // 111 − 0.5 + 6
    }

    public function test_money_nested_in_sub_spins_and_mystery_values_is_scaled_too(): void
    {
        [, , $s] = $this->apolloGame();
        $step = $this->step(51.0, 1.5, 0);
        $step['gameState'] += ['mysteryValues' => ['7' => 1.5], 'multipliers' => [3],
            'gameSpins' => [['winning' => 1.5, 'winnings' => [1.5, 0], 'multipliers' => [3], 'reelsSymbols' => [[1, 2]]]]];
        $round = ApolloRound::create(['game_key' => 'testgame_ag', 'bet_cents' => 50, 'win_cents' => 150, 'start_credit' => 5000, 'steps' => [$step]]);
        Cache::put('apollo:force:testgame_ag', $round->id, 60);
        $this->act($s, 'casino.init');

        $gs = $this->act($s, 'casino.spin', ['stake' => 2.5])['gameState'];   // × 5
        $this->assertEquals(['7' => 7.5], $gs['mysteryValues']);
        $this->assertEquals(7.5, $gs['gameSpins'][0]['winning']);
        $this->assertEquals([7.5, 0], $gs['gameSpins'][0]['winnings']);
        $this->assertEquals([3], $gs['gameSpins'][0]['multipliers']);   // multipliers aren't money
        $this->assertEquals([3], $gs['multipliers']);
        $this->assertEquals([[1, 2]], $gs['gameSpins'][0]['reelsSymbols']);
    }

    public function test_bad_stakes_and_low_credit_are_refused(): void
    {
        [, $player, $s] = $this->apolloGame();
        ApolloRound::create(['game_key' => 'testgame_ag', 'bet_cents' => 50, 'win_cents' => 0, 'start_credit' => 5000,
            'steps' => [$this->step(49.5, 0, 0)]]);
        $this->act($s, 'casino.init');

        $this->assertSame('INVALID_STAKE', $this->act($s, 'casino.spin', ['stake' => 0.7], 400)['code']);
        $player->wallet->update(['balance' => 0.4]);
        $this->assertSame('LOW_CREDIT', $this->act($s, 'casino.spin', ['stake' => 0.5], 400)['code']);
        $this->assertEquals(0.4, (float) $player->wallet->fresh()->balance);
    }

    public function test_the_gamble_doubles_or_takes_back_the_last_win_only(): void
    {
        [$game, $player, $s] = $this->apolloGame();
        $game->update(['reserve_percent' => 1]);   // gamble win chance 1 in 1
        $round = ApolloRound::create(['game_key' => 'testgame_ag', 'bet_cents' => 50, 'win_cents' => 300, 'start_credit' => 5000,
            'steps' => [$this->step(52.5, 3, 0)]]);
        $this->act($s, 'casino.init');
        $this->assertSame('INVALID_STAKE', $this->act($s, 'casino.gamble', ['stake' => 3, 'isBetOnBlack' => true], 400)['code']);

        Cache::put('apollo:force:testgame_ag', $round->id, 60);
        $this->act($s, 'casino.spin', ['stake' => 0.5]);   // 100 − 0.5 + 3
        $this->assertSame('INVALID_STAKE', $this->act($s, 'casino.gamble', ['stake' => 4, 'isBetOnBlack' => true], 400)['code']);

        $g = $this->act($s, 'casino.gamble', ['stake' => 3, 'isBetOnBlack' => true]);
        $this->assertEquals(6, $g['winval']);
        $this->assertSame(0, $g['gameState']['tossedSymbol']);   // black
        $this->assertEquals(105.5, $g['credit']);
        $this->assertEquals(105.5, (float) $player->wallet->fresh()->balance);

        GameBank::query()->update(['slots' => 0]);   // a bank that can't cover the double: a forced loss
        $g = $this->act($s, 'casino.gamble', ['stake' => 6, 'isBetOnBlack' => false]);
        $this->assertEquals(0, $g['winval']);
        $this->assertSame(0, $g['gameState']['tossedSymbol']);   // bet red, black came
        $this->assertEquals(99.5, (float) $player->wallet->fresh()->balance);
        $this->assertSame('INVALID_STAKE', $this->act($s, 'casino.gamble', ['stake' => 1, 'isBetOnBlack' => true], 400)['code']);
    }

    public function test_gangster_worlds_card_gamble_deals_then_resolves_with_cards_that_fit_the_outcome(): void
    {
        [$game, $player, $s] = $this->apolloGame();
        $game->update(['reserve_percent' => 1]);   // always win
        $round = ApolloRound::create(['game_key' => 'testgame_ag', 'bet_cents' => 50, 'win_cents' => 300, 'start_credit' => 5000,
            'steps' => [$this->step(52.5, 3, 0)]]);
        $this->act($s, 'casino.init');
        Cache::put('apollo:force:testgame_ag', $round->id, 60);
        $this->act($s, 'casino.spin', ['stake' => 0.5]);

        $deal = $this->act($s, 'casino.gamble', ['stake' => 0, 'getGWODealersCards' => true])['gameState']['cardTossState'];
        $this->assertNull($deal['playersCards']);
        $this->assertEquals(102.5, (float) $player->wallet->fresh()->balance);   // dealing moves no money

        $ranks = ['CARD_2', 'CARD_3', 'CARD_4', 'CARD_5', 'CARD_6', 'CARD_7', 'CARD_8', 'CARD_9', 'CARD_10', 'CARD_J', 'CARD_Q', 'CARD_K', 'CARD_A'];
        $pick = $this->act($s, 'casino.gamble', ['stake' => 3, 'selectedCardIndex' => 2]);
        $toss = $pick['gameState']['cardTossState'];
        $this->assertSame($deal['dealerCard'], $toss['dealerCard']);
        $this->assertSame('WIN', $toss['cardTossingResult']);
        $this->assertCount(4, $toss['playersCards']);
        $this->assertGreaterThan(array_search($toss['dealerCard']['type'], $ranks), array_search($toss['playersCards'][2]['type'], $ranks));
        $this->assertEquals(6, $toss['winning']);
        $this->assertEquals(105.5, (float) $player->wallet->fresh()->balance);

        GameBank::query()->update(['slots' => 0]);   // forced loss
        $this->act($s, 'casino.gamble', ['stake' => 0, 'getGWODealersCards' => true]);
        $toss = $this->act($s, 'casino.gamble', ['stake' => 6, 'selectedCardIndex' => 0])['gameState']['cardTossState'];
        $this->assertSame('LOSS', $toss['cardTossingResult']);
        $this->assertLessThan(array_search($toss['dealerCard']['type'], $ranks), array_search($toss['playersCards'][0]['type'], $ranks));
        $this->assertEquals(99.5, (float) $player->wallet->fresh()->balance);
    }

    public function test_an_unknown_session_is_refused(): void
    {
        $this->postJson('/apollo/nope/casino.init', [])->assertStatus(401)->assertJson(['code' => 'UNKNOWN_SESSION']);
    }

    public function test_frames_parse_into_headers_and_body(): void
    {
        [$h, $body] = ApolloLiveClient::parse("response=casino.spin\nstatus=200\nContent-Type=application/json;charset=utf-8\n\n{\"credit\":99.5}");
        $this->assertSame('casino.spin', $h['response']);
        $this->assertSame('200', $h['status']);
        $this->assertSame(['credit' => 99.5], $body);
        $this->assertTrue(ApolloLiveClient::continues(['gameState' => ['numberOfBonusRounds' => 3]]));
        $this->assertFalse(ApolloLiveClient::continues(['gameState' => ['numberOfBonusRounds' => 0]]));
    }
}
