<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\PragmaticRound;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\PragmaticPlay\PragmaticLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pragmatic replies are recorded at the smallest coin and served at the
 * player's: money fields × coin / recorded coin, the balance ours — the
 * round's win paid once, at the paid spin; tumbles / free spins / collects
 * follow, unpaid, whatever action names the client sends for them.
 */
class PragmaticPlayProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const string INIT = 'def_s=3,4,5&balance=100&cfgs=1&ver=2&index=1&balance_cash=100&def_sb=5,3&def_sa=4,5&balance_bonus=0.00&na=s'
        .'&scatters=&gmb=0,0,0&rt=d&stime=1&sa=4,5&sb=5,3&sc=0.01,50&defc=0.01&sh=5&wilds=&bonuses=0&c=0.01&sver=5&counter=2'
        .'&paytable=0,0;1000,500&l=20&rtp=96.00&s=3,4,5&purInit=[{type:"fs",bet:2000,fs_count:10}]&bl=0&bls=20,25';

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function pragmaticGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestPragmatic', 'title' => 'Test Pragmatic', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::PragmaticPlay, 'default_denomination' => 1,
            'bonus_config' => ['pragmatic' => ['game_key' => 'vs20test_prg', 'symbol' => 'vs20test_prg', 'lines' => 20,
                'record_coin' => 0.01, 'bet_cents' => 20, 'coins' => [0.01, 0.02, 0.05, 0.10], 'languages' => ['en'],
                'init' => self::INIT, 'config' => ['styleName' => 'generic']]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.2, 0.4, 1.0], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'prg'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, string> */
    private function gs(GameSession $s, string $action, array $params = []): array
    {
        $body = $this->post("/pragmatic/{$s->token}/gameService", ['action' => $action, 'symbol' => 'vs20test_prg',
            'index' => 7, 'counter' => 13, 'repeat' => 0, 'mgckey' => $s->token] + $params)->assertOk()->getContent();

        return PragmaticLiveClient::parse((string) $body);
    }

    public function test_init_carries_our_balance_and_coins_and_no_buy_feature(): void
    {
        [, , $s] = $this->pragmaticGame();
        $r = $this->gs($s, 'doInit');
        $this->assertSame('100.00', $r['balance']);
        $this->assertSame('100.00', $r['balance_cash']);
        $this->assertSame('0.01,0.02,0.05', $r['sc']);   // 0.10 → a 2.00 stake the game doesn't offer
        $this->assertSame('0.01', $r['defc']);
        $this->assertSame('20', $r['l']);
        $this->assertSame('s', $r['na']);
        $this->assertSame('7', $r['index']);
        $this->assertSame('14', $r['counter']);
        $this->assertArrayNotHasKey('purInit', $r);
        $this->assertSame('1000,500', explode(';', $r['paytable'])[1]);
    }

    public function test_a_tumbling_round_is_paid_at_the_spin_and_served_scaled(): void
    {
        [, $player, $s] = $this->pragmaticGame();
        // recorded at coin 0.01 (stake 0.20) from 100.00: a tumble wins 0.40, then 0.25 more, collected → 100.45
        $round = PragmaticRound::create(['game_key' => 'vs20test_prg', 'bet_cents' => 20, 'win_cents' => 65, 'feature' => false,
            'start_credit' => 10000, 'steps' => [
                ['a' => 'doSpin', 'p' => ['c' => '0.01', 'l' => 20], 'r' => 'tw=0.4&balance=99.8&index=2&balance_cash=99.8&balance_bonus=0.00&na=s&rs=mc&rs_p=0&tmb_win=0.4&rs_win=0.4&c=0.01&l=20&s=1,2,3&w=0.4&l0=0~0.40~1~2~3&rs_m=2&mo=0,5,0&mo_tv=5&mo_tw=0.4'],
                ['a' => 'doSpin', 'p' => ['c' => '0.01', 'l' => 20], 'r' => 'tw=0.65&balance=99.8&index=3&balance_cash=99.8&balance_bonus=0.00&na=c&rs_t=1&tmb_win=0.65&c=0.01&l=20&s=1,2,3&w=0.25&wlc_v=4~0.25~8~1~1,2,3~1;5~0.00~8~1~4~1&psym=1~0.25~0,1'],
                ['a' => 'doCollect', 'p' => [], 'r' => 'balance=100.45&index=4&balance_cash=100.45&balance_bonus=0.00&na=s&stime=1&sver=5&counter=8&w=0.65'],
            ]]);
        Cache::put('pragmatic:force:vs20test_prg', $round->id, 60);
        $this->gs($s, 'doInit');

        // at coin 0.05 (× 5): stake 1.00 taken, whole win (3.25) paid at once
        $r = $this->gs($s, 'doSpin', ['c' => '0.05', 'l' => '20']);
        $this->assertEquals(102.25, (float) $player->wallet->fresh()->balance);
        $this->assertSame('99.00', $r['balance']);   // as the recording showed it, × 5
        $this->assertSame('0.05', $r['c']);
        $this->assertSame('2', $r['tw']);
        $this->assertSame('2', $r['tmb_win']);
        $this->assertSame('0~2~1~2~3', $r['l0']);
        $this->assertSame('0,5,0', $r['mo']);       // multipliers, not money
        $this->assertSame('5', $r['mo_tv']);
        $this->assertSame('2', $r['mo_tw']);
        $this->assertSame('2', $r['rs_m']);
        $this->assertSame('7', $r['index']);
        $this->assertSame('14', $r['counter']);

        $r = $this->gs($s, 'doSpin', ['c' => '0.05', 'l' => '20']);   // the tumble: no stake
        $this->assertSame('3.25', $r['tw']);
        $this->assertSame('4~1.25~8~1~1,2,3~1;5~0~8~1~4~1', $r['wlc_v']);
        $this->assertSame('1~1.25~0,1', $r['psym']);
        $this->assertSame('c', $r['na']);
        $this->assertEquals(102.25, (float) $player->wallet->fresh()->balance);

        $r = $this->gs($s, 'doCollect');
        $this->assertSame('102.25', $r['balance']);   // the round's last reply: our real balance
        $this->assertSame('3.25', $r['w']);
        $this->assertSame('s', $r['na']);

        // a stray collect afterwards: idle
        $r = $this->gs($s, 'doCollect');
        $this->assertSame('102.25', $r['balance']);
        $this->assertSame('s', $r['na']);
    }

    public function test_a_reload_mid_round_resumes_it(): void
    {
        [, $player, $s] = $this->pragmaticGame();
        $round = PragmaticRound::create(['game_key' => 'vs20test_prg', 'bet_cents' => 20, 'win_cents' => 40, 'feature' => false,
            'start_credit' => 10000, 'steps' => [
                ['a' => 'doSpin', 'p' => [], 'r' => 'tw=0.4&balance=99.8&na=c&c=0.01&l=20&s=1,2,3&w=0.4&l0=0~0.40~1~2~3'],
                ['a' => 'doCollect', 'p' => [], 'r' => 'balance=100.2&na=s&w=0.4'],
            ]]);
        Cache::put('pragmatic:force:vs20test_prg', $round->id, 60);
        $this->gs($s, 'doSpin', ['c' => '0.02', 'l' => '20']);

        $r = $this->gs($s, 'doInit');
        $this->assertSame('c', $r['na']);
        $this->assertSame('0.8', $r['tw']);
        $this->assertSame('99.60', $r['balance']);
        $r = $this->gs($s, 'doCollect');
        $this->assertSame('100.40', $r['balance']);
        $this->assertEquals(100.4, (float) $player->wallet->fresh()->balance);
    }

    public function test_a_box_the_player_picks_holds_the_recorded_prize(): void
    {
        [, , $s] = $this->pragmaticGame();
        $round = PragmaticRound::create(['game_key' => 'vs20test_prg', 'bet_cents' => 20, 'win_cents' => 120, 'feature' => true,
            'start_credit' => 10000, 'steps' => [
                ['a' => 'doSpin', 'p' => [], 'r' => 'tw=0&balance=99.8&na=b&c=0.01&l=20&status=0,0,0,0&wins=0,0,0,0&wins_mask=h,h,h,h'],
                ['a' => 'doBonus', 'p' => ['ind' => 2], 'r' => 'balance=99.8&na=b&status=0,0,1,0&wins=0,0,5,0&wins_mask=h,h,pw,h&rw=0.5'],
                ['a' => 'doBonus', 'p' => ['ind' => 0], 'r' => 'balance=99.8&na=cb&status=2,0,1,0&wins=7,0,5,0&wins_mask=pw,h,pw,h&rw=1.2'],
                ['a' => 'doCollectBonus', 'p' => [], 'r' => 'balance=101&na=s&rw=1.2'],
            ]]);
        Cache::put('pragmatic:force:vs20test_prg', $round->id, 60);
        $this->gs($s, 'doSpin', ['c' => '0.01', 'l' => '20']);

        $r = $this->gs($s, 'doBonus', ['ind' => '3']);
        $this->assertSame('0,0,0,1', $r['status']);
        $this->assertSame('0,0,0,5', $r['wins']);
        $this->assertSame('h,h,h,pw', $r['wins_mask']);
        $r = $this->gs($s, 'doBonus', ['ind' => '1']);
        $this->assertSame('0,2,0,1', $r['status']);
        $this->assertSame('0,7,0,5', $r['wins']);
        $r = $this->gs($s, 'doCollectBonus');
        $this->assertSame('101.00', $r['balance']);
    }

    public function test_another_free_spin_option_carries_on_in_a_round_where_it_was_chosen(): void
    {
        [, $player, $s] = $this->pragmaticGame();
        $option = fn (int $ind, int $win, string $fs) => PragmaticRound::create(['game_key' => 'vs20test_prg', 'bet_cents' => 20,
            'win_cents' => $win, 'feature' => true, 'start_credit' => 10000, 'steps' => [
                ['a' => 'doSpin', 'p' => [], 'r' => 'tw=0&balance=99.8&na=fso&c=0.01&l=20&fs_opt=25,1~10,3'],
                ['a' => 'doFSOption', 'p' => ['ind' => $ind], 'r' => "balance=99.8&na=s&fs=1&fsmax={$fs}&fsopt_i={$ind}"],
                ['a' => 'doSpin', 'p' => [], 'r' => "tw=".($win / 100)."&balance=99.8&na=c&fs_total={$fs}&fswin_total=".($win / 100)],
                ['a' => 'doCollect', 'p' => [], 'r' => 'balance='.((10000 - 20 + $win) / 100).'&na=s'],
            ]]);
        $recorded = $option(0, 300, '25');
        $option(1, 500, '10');
        Cache::put('pragmatic:force:vs20test_prg', $recorded->id, 60);
        $this->gs($s, 'doSpin', ['c' => '0.02', 'l' => '20']);
        $this->assertEquals(100 - 0.4 + 6, (float) $player->wallet->fresh()->balance);

        $r = $this->gs($s, 'doFSOption', ['ind' => '1']);   // the player takes the other option
        $this->assertSame('10', $r['fsmax']);
        $this->assertSame('99.60', $r['balance']);
        $this->assertEquals(100 - 0.4 + 10, (float) $player->wallet->fresh()->balance);   // that round's win, × 2
        $r = $this->gs($s, 'doSpin', ['c' => '0.02', 'l' => '20']);
        $this->assertSame('10', $r['fswin_total']);
        $r = $this->gs($s, 'doCollect');
        $this->assertSame('109.60', $r['balance']);
    }

    public function test_bad_bets_are_refused_and_nothing_is_staked(): void
    {
        [, $player, $s] = $this->pragmaticGame();
        PragmaticRound::create(['game_key' => 'vs20test_prg', 'bet_cents' => 20, 'win_cents' => 0, 'feature' => false,
            'start_credit' => 10000, 'steps' => [['a' => 'doSpin', 'p' => [], 'r' => 'tw=0&balance=99.8&na=s&c=0.01&l=20&w=0']]]);
        $this->gs($s, 'doInit');

        $this->assertArrayHasKey('frozen', $this->gs($s, 'doSpin', ['c' => '0.10', 'l' => '20']));   // not a coin offered
        $this->assertArrayHasKey('frozen', $this->gs($s, 'doSpin', ['c' => '0.01', 'l' => '10']));   // not the game's lines
        $this->assertArrayHasKey('frozen', $this->gs($s, 'doSpin', ['c' => '0.01', 'l' => '20', 'bl' => '1']));   // ante
        $this->assertArrayHasKey('frozen', $this->gs($s, 'doSpin', ['c' => '0.01', 'l' => '20', 'pur' => '0']));   // buy feature
        $player->wallet->update(['balance' => 0.5]);
        $r = $this->gs($s, 'doSpin', ['c' => '0.05', 'l' => '20']);
        $this->assertSame('1', $r['nomoney']);
        $this->assertEquals(0.5, (float) $player->wallet->fresh()->balance);

        $r = $this->gs($s, 'doSpin', ['c' => '0.01', 'l' => '20']);
        $this->assertSame('0.30', $r['balance']);
    }

    public function test_settings_are_kept_per_player_and_unknown_sessions_are_unlogged(): void
    {
        [, , $s] = $this->pragmaticGame();
        $this->post("/pragmatic/{$s->token}/saveSettings.do", ['id' => 'vs20test_prg', 'settings' => 'SoundState=true;Intro=false'])->assertOk();
        $this->assertSame('SoundState=true;Intro=false',
            $this->post("/pragmatic/{$s->token}/saveSettings.do", ['method' => 'load', 'id' => 'vs20test_prg'])->getContent());
        $this->assertSame('', $this->post("/pragmatic/{$s->token}/saveSettings.do", ['method' => 'load', 'id' => 'vsCommon'])->getContent());
        $this->assertSame('unlogged', $this->post('/pragmatic/nope/gameService', ['action' => 'doInit'])->getContent());
    }

    public function test_retire_old_hides_the_legacy_pragmatic_games_only(): void
    {
        [$new] = $this->pragmaticGame();
        $category = Category::firstOrCreate(['slug' => 'pragmatic'], ['title' => 'Pragmatic', 'type' => 'provider']);
        $make = function (string $code, ?ClientProtocol $protocol) use ($new, $category) {
            $tpl = GameTemplate::create(['code' => $code, 'title' => $code, 'engine' => 'internal', 'device' => 'both',
                'bank_type' => 'slots', 'client_protocol' => $protocol, 'default_denomination' => 1, 'is_active' => true]);
            $game = Game::create(['shop_id' => $new->shop_id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
                'is_visible' => true, 'bet_options' => [1], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
            if ($protocol === null) {
                $game->categories()->attach($category->id);
            }

            return $tpl;
        };
        $tumble = $make('SweetBonanza', ClientProtocol::PragmaticTumble);
        $classic = $make('AncientEgyptPM', ClientProtocol::Pragmatic);
        $byCategory = $make('WolfGold', null);
        $other = $make('Champ', ClientProtocol::Booongo);
        $new->categories()->attach($category->id);

        $this->artisan('pragmatic:retire-old')->assertSuccessful();

        foreach ([$tumble, $classic, $byCategory] as $t) {
            $this->assertFalse($t->fresh()->is_active, $t->code);
            $this->assertFalse((bool) Game::where('template_id', $t->id)->value('is_visible'), $t->code);
        }
        $this->assertTrue((bool) $other->fresh()->is_active);
        $this->assertTrue((bool) $new->fresh()->is_visible);
        $this->assertNotFalse($new->template->fresh()->is_active);
    }
}
