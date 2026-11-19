<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\IgtRound;
use App\Models\IgtSetup;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Igt\IgtLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * IGT RGS replies are recorded at 1 credit a line on every line and served at
 * k credits a line: credit amounts × k, the balance ours — the round's payout
 * paid once, at the paid play; free spins follow, unpaid.
 */
class IgtProtocolTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function igtGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'I', 'slug' => 'i', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestIgt', 'title' => 'Test IGT', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Igt, 'default_denomination' => 1,
            'bonus_config' => ['igt' => ['game_key' => 'test_igt_html', 'folder' => 'test_igt_html', 'patterns' => 40, 'denom' => 0.01, 'record_step' => 1, 'bet_cents' => 40,
                'bet_steps' => [1, 2, 5], 'languages' => ['en'],
                'paytable' => ['PatternSliderInfo' => ['PatternInfo' => ['@max' => '40', '@min' => '1', 'Step' => ['1', '40']],
                    'BetInfo' => ['@max' => '5', '@min' => '1', 'Step' => ['1', '2', '5']]], 'AwardCapInfo' => ['CurrencyCap' => ['CurrencyType' => 'FPY']]],
                'init' => ['OutcomeDetail' => ['TransactionId' => 'R1-0', 'Stage' => 'BaseGame', 'NextStage' => 'BaseGame', 'Balance' => '100',
                    'GameStatus' => 'Initial', 'Settled' => '0', 'Pending' => '0', 'Payout' => '0'],
                    'PatternSliderInput' => ['BetPerPattern' => '1', 'PatternsBet' => '40']],
                'currency' => ['CURRENCIES' => ['CURRENCY' => ['@currencyCode' => 'FPY', 'MAJOR_SYMBOL' => [' '], 'DECIMAL_PRECISION' => '2']]]]],
        ]);
        $cfg = $tpl->bonus_config;   // the wide part lives in igt_setups
        IgtSetup::create(['game_key' => 'test_igt_html', 'paytable' => $cfg['igt']['paytable'], 'init' => $cfg['igt']['init'], 'currency' => $cfg['igt']['currency']]);
        unset($cfg['igt']['paytable'], $cfg['igt']['init'], $cfg['igt']['currency']);
        $tpl->update(['bonus_config' => $cfg]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.4, 0.8, 2], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'ig'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, mixed> */
    private function rgs(GameSession $s, string $action, ?array $body = null): array
    {
        $url = "/igt/{$s->token}/rgs?/{$action}&skincode=GL02&currencycode=EUR";

        return (array) ($body === null ? $this->getJson($url) : $this->postJson($url, $body))->assertOk()->json();
    }

    private function play(GameSession $s, int $perLine, int $lines = 40): array
    {
        return $this->rgs($s, 'play', ['GameLogicRequest' => ['ActionInput' => ['Action' => 'play'],
            'PatternSliderInput' => ['BetPerPattern' => $perLine, 'PatternsBet' => $lines], 'TransactionId' => 'R1-0']]);
    }

    /** A recorded /play reply: balance (money at 0.01), credits settled / pending / paid. */
    private function step(string $balance, string $stage, string $next, string $payout, string $pay = '0'): array
    {
        return ['OutcomeDetail' => ['TransactionId' => 'R9-1', 'Stage' => $stage, 'NextStage' => $next, 'Balance' => $balance,
            'GameStatus' => $next === 'BaseGame' ? 'Start' : 'InProgress', 'Settled' => $next === 'BaseGame' ? '40' : '0',
            'Pending' => $next === 'BaseGame' ? '0' : '40', 'Payout' => $payout],
            'PrizeOutcome' => [['@name' => 'BaseGame.Lines', '@multiplier' => '1', '@pay' => $pay, '@totalPay' => $pay,
                'Prize' => ['@betMultiplier' => '1', '@pay' => $pay, '@totalPay' => $pay]]],
            'FreeSpinOutcome' => ['Count' => '1', 'Countdown' => '4'],
            'WagerOutcome' => [['@amount' => '40', '@name' => 'Game.Total', 'Wager' => [['@amount' => '1', '@name' => 'Line 1']]]],
            'Balances' => ['@totalBalance' => $balance]];
    }

    public function test_paytable_pins_lines_and_offers_only_stakes_the_game_has(): void
    {
        [, , $s] = $this->igtGame();
        $p = $this->rgs($s, 'paytable')['PaytableResponse'];
        $this->assertSame(['@max' => '40', '@min' => '40', 'Step' => ['40', '40']], $p['PatternSliderInfo']['PatternInfo']);   // one step twice: a single step disables the older clients' bet buttons
        $this->assertSame(['1', '2', '5'], $p['PatternSliderInfo']['BetInfo']['Step']);   // 0.40, 0.80, 2.00
        $this->assertSame('EUR', $p['AwardCapInfo']['CurrencyCap']['CurrencyType']);
        $this->assertSame('EUR', $this->rgs($s, 'currency')['CURRENCIES']['CURRENCY']['@currencyCode']);
        $this->assertSame(['Authentication' => ['Status' => 'Success']], $this->rgs($s, 'authenticate', []));

        $init = $this->rgs($s, 'initstate')['GameLogicResponse'];
        $this->assertSame('100', $init['OutcomeDetail']['Balance']);
        $this->assertSame('100', $init['Balances']['@totalBalance']);
    }

    public function test_a_round_with_free_spins_is_paid_at_the_play_and_served_scaled(): void
    {
        [, $player, $s] = $this->igtGame();
        // recorded from 50.00: the trigger pays 10 credits, then two free spins; the round pays 110 credits
        $round = IgtRound::create(['game_key' => 'test_igt_html', 'bet_cents' => 40, 'win_cents' => 110, 'feature' => true, 'start_credit' => 5000,
            'steps' => [$this->step('49.6', 'BaseGame', 'FreeSpin', '0', '10'), $this->step('49.6', 'FreeSpin', 'FreeSpin', '0', '50'),
                $this->step('50.7', 'FreeSpin', 'BaseGame', '110', '50')]]);
        Cache::put('igt:force:test_igt_html', $round->id, 60);
        $this->rgs($s, 'initstate');

        $g = $this->play($s, 2)['GameLogicResponse'];   // 0.80 a spin: × 2
        $this->assertEquals(101.4, (float) $player->wallet->fresh()->balance);   // 100 − 0.80 + 2.20, at once
        $this->assertSame('99.2', $g['OutcomeDetail']['Balance']);               // as the recording showed it: after the stake
        $this->assertSame('80', $g['OutcomeDetail']['Pending']);
        $this->assertSame('20', $g['PrizeOutcome'][0]['@pay']);
        $this->assertSame('20', $g['PrizeOutcome'][0]['Prize']['@totalPay']);
        $this->assertSame('1', $g['PrizeOutcome'][0]['Prize']['@betMultiplier']);   // not credits
        $this->assertSame('2', $g['WagerOutcome'][0]['Wager'][0]['@amount']);
        $this->assertSame('4', $g['FreeSpinOutcome']['Countdown']);
        $this->assertSame('2', $g['PatternSliderInput']['BetPerPattern']);

        $this->play($s, 2);                                   // a free spin: nothing taken
        $g = $this->play($s, 2)['GameLogicResponse'];         // the last: our real balance
        $this->assertSame('220', $g['OutcomeDetail']['Payout']);
        $this->assertSame('101.4', $g['OutcomeDetail']['Balance']);
        $this->assertEquals(101.4, (float) $player->wallet->fresh()->balance);

        Cache::put('igt:force:test_igt_html', $round->id, 60);
        $this->play($s, 1);                                   // a new paid round
        $this->assertEquals(102.1, (float) $player->wallet->fresh()->balance);   // − 0.40 + 1.10
    }

    public function test_other_line_counts_bad_bets_and_low_balance_are_refused(): void
    {
        [, $player, $s] = $this->igtGame();
        IgtRound::create(['game_key' => 'test_igt_html', 'bet_cents' => 40, 'win_cents' => 0, 'start_credit' => 5000,
            'steps' => [$this->step('49.6', 'BaseGame', 'BaseGame', '0')]]);
        $this->rgs($s, 'initstate');

        $this->assertSame('Invalid bet', $this->play($s, 1, 20)['Exception']['Message']);
        $this->assertSame('Invalid bet', $this->play($s, 3)['Exception']['Message']);
        $player->wallet->update(['balance' => 0.3]);
        $this->assertSame('Insufficient funds', $this->play($s, 1)['Exception']['Message']);
        $this->assertEquals(0.3, (float) $player->wallet->fresh()->balance);
        $this->assertSame('1005', $this->getJson('/igt/nope/rgs?/initstate')->json('Exception.Id'));
    }

    public function test_a_game_priced_in_money_lines_bets_like_cleopatra_scales_by_the_line_bet(): void
    {
        [$game, $player, $s] = $this->igtGame();
        $tpl = $game->template;
        $cfg = $tpl->bonus_config;
        // Cleopatra: denomination 1, line bets 0.01 … 5 (money), 20 lines; recorded at 0.01
        $cfg['igt'] = array_merge($cfg['igt'], ['patterns' => 20, 'denom' => 1, 'record_step' => 0.01, 'bet_cents' => 20,
            'bet_steps' => ['0.01', '0.02', '0.05', '0.1']]);
        $tpl->update(['bonus_config' => $cfg]);
        $game->update(['bet_options' => [0.2, 0.4, 1, 2]]);
        $step = $this->step('49.8', 'BaseGame', 'BaseGame', '0.5', '0.5');
        $step['OutcomeDetail']['Settled'] = '0.2';
        $round = IgtRound::create(['game_key' => 'test_igt_html', 'bet_cents' => 20, 'win_cents' => 50, 'start_credit' => 5000, 'steps' => [$step]]);
        Cache::put('igt:force:test_igt_html', $round->id, 60);

        $this->assertSame(['0.01', '0.02', '0.05', '0.1'], $this->rgs($s, 'paytable')['PaytableResponse']['PatternSliderInfo']['BetInfo']['Step']);
        $this->rgs($s, 'initstate');
        $this->assertSame('Invalid bet', $this->play($s, 1, 20)['Exception']['Message']);
        $g = $this->rgs($s, 'play', ['GameLogicRequest' => ['ActionInput' => ['Action' => 'play'],
            'PatternSliderInput' => ['BetPerPattern' => 0.05, 'PatternsBet' => 20]]])['GameLogicResponse'];   // × 5: 1.00 a spin
        $this->assertSame('2.5', $g['OutcomeDetail']['Payout']);
        $this->assertSame('1', $g['OutcomeDetail']['Settled']);
        $this->assertEquals(101.5, (float) $player->wallet->fresh()->balance);   // 100 − 1 + 2.50
    }

    public function test_a_round_runs_while_the_next_stage_is_a_feature(): void
    {
        $this->assertTrue(IgtLiveClient::continues(['OutcomeDetail' => ['NextStage' => 'FreeSpin', 'GameStatus' => 'InProgress']]));
        $this->assertTrue(IgtLiveClient::continues(['OutcomeDetail' => ['NextStage' => 'BaseGame', 'GameStatus' => 'InProgress']]));
        $this->assertFalse(IgtLiveClient::continues(['OutcomeDetail' => ['NextStage' => 'BaseGame', 'GameStatus' => 'Start']]));
    }
}
