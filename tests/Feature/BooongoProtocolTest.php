<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\BooongoRound;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Booongo\BooongoLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Booongo replies are recorded at the smallest line bet and served at the
 * player's: money fields × line bet / recorded line bet, the balance ours —
 * the round's win paid once, at the paid spin; free spins / respins follow,
 * unpaid, whatever action names the client sends for them.
 */
class BooongoProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const array SETTINGS = ['bets' => [1, 2, 5, 10], 'lines' => [10], 'bet_factor' => [10], 'cols' => 5, 'rows' => 3,
        'currency_format' => ['currency_style' => 'symbol', 'denominator' => 100, 'style' => 'money'], 'paytable' => []];

    private const array IDLE = ['actions' => ['spin'], 'round_finished' => true, 'current' => 'spins', 'last_action' => 'init', 'last_win' => 0,
        'spins' => ['bet_per_line' => 1, 'lines' => 10, 'round_bet' => 10, 'round_win' => 0, 'total_win' => 0, 'winlines' => [], 'board' => [[1, 2, 3]]]];

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function booongoGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestBooongo', 'title' => 'Test Booongo', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Booongo, 'default_denomination' => 1,
            'bonus_config' => ['booongo' => ['game_key' => 'test_bng_html', 'folder' => 'test_bng_html', 'lines' => 10, 'bet_factor' => 10,
                'record_bet' => 1, 'bet_cents' => 10, 'bets' => [1, 2, 5, 10], 'languages' => ['en'], 'settings' => self::SETTINGS, 'context' => self::IDLE]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.1, 0.2, 0.5], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'bng'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, mixed> */
    private function gsc(GameSession $s, string $command, array $body = []): array
    {
        return (array) $this->postJson("/booongo/{$s->token}/engine?gsc={$command}", ['command' => $command, 'request_id' => 'r1'] + $body)
            ->assertOk()->json();
    }

    private function spin(GameSession $s, float $bpl, int $lines = 10): array
    {
        return $this->gsc($s, 'play', ['action' => ['name' => 'spin', 'params' => ['bet_per_line' => $bpl, 'lines' => $lines]]]);
    }

    /** One recorded step: a context section with its own win, and the recorded balance (cents) after it. */
    private function step(string $action, string $current, int $win, int $total, int $lastWin, int $balance, array $next, bool $finished = false): array
    {
        return ['balance' => $balance, 'context' => ['actions' => $next, 'round_finished' => $finished, 'current' => $current,
            'last_action' => $action, 'last_win' => $lastWin,
            $current => ['bet_per_line' => 1, 'lines' => 10, 'round_bet' => 10, 'round_win' => $win, 'total_win' => $total,
                'winlines' => $win > 0 ? [['amount' => $win, 'line' => 1, 'occurrences' => 3, 'symbol' => 4]] : [],
                'bs_v' => [[$win, 0, 0]], 'bs_values' => [[3, 0, 0]], 'board' => [[1, 2, 3]],
                'bs' => [['position' => 2, 'reel' => 1, 'value' => $win]], 'jackpotMultiple' => [[4, 0]], 'mystery_values' => [20]]]];
    }

    public function test_login_and_start_carry_our_balance_currency_and_bets(): void
    {
        [, , $s] = $this->booongoGame();
        $login = $this->gsc($s, 'login', ['token' => $s->token, 'language' => 'en']);
        $this->assertSame('OK', $login['status']['code']);
        $this->assertSame(10000, $login['user']['balance']);
        $this->assertSame('EUR', $login['user']['currency']);
        $this->assertSame('r1', $login['request_id']);

        $start = $this->gsc($s, 'start', ['mode' => 'play']);
        $this->assertSame([1, 2, 5], $start['settings']['bets']);   // 10 → a 1.00 stake the game doesn't offer
        $this->assertSame(['spin'], $start['context']['actions']);
        $this->assertGreaterThan($login['user']['balance_version'], $start['user']['balance_version']);
    }

    public function test_a_round_with_free_spins_is_paid_at_the_spin_and_served_scaled(): void
    {
        [, $player, $s] = $this->booongoGame();
        // recorded at line bet 1 (stake 10¢) from 10000¢: the spin wins 20 and triggers free spins (+50, +0), stop
        $round = BooongoRound::create(['game_key' => 'test_bng_html', 'bet_cents' => 10, 'win_cents' => 70, 'feature' => true, 'start_credit' => 10000, 'steps' => [
            $this->step('spin', 'spins', 20, 20, 20, 10010, ['freespin_init']),
            $this->step('freespin_init', 'freespins', 0, 20, 20, 10010, ['freespin']),
            $this->step('freespin', 'freespins', 50, 70, 50, 10060, ['freespin']),
            $this->step('freespin', 'freespins', 0, 70, 50, 10060, ['freespin_stop']),
            $this->step('freespin_stop', 'spins', 0, 70, 70, 10060, ['spin'], true),
        ]]);
        Cache::put('booongo:force:test_bng_html', $round->id, 60);
        $this->gsc($s, 'start');

        // at line bet 5 (× 5): stake 0.50 taken, whole win (3.50) paid at once
        $r = $this->spin($s, 5);
        $this->assertEquals(103, (float) $player->wallet->fresh()->balance);
        $this->assertSame(10000 - 50 + 100, $r['user']['balance']);   // as the recording showed it, × 5
        $this->assertSame(5, $r['context']['spins']['bet_per_line']);
        $this->assertSame(50, $r['context']['spins']['round_bet']);
        $this->assertSame(100, $r['context']['spins']['winlines'][0]['amount']);
        $this->assertSame([[100, 0, 0]], $r['context']['spins']['bs_v']);
        $this->assertSame([[3, 0, 0]], $r['context']['spins']['bs_values']);   // multipliers, not money
        $this->assertEquals([['position' => 2, 'reel' => 1, 'value' => 100]], $r['context']['spins']['bs']);
        $this->assertSame([[4, 0]], $r['context']['spins']['jackpotMultiple']);
        $this->assertSame([20], $r['context']['spins']['mystery_values']);
        $this->assertSame(100, $r['context']['last_win']);
        $this->assertSame(['freespin_init'], $r['context']['actions']);

        $this->assertSame('ROUND_IN_PROGRESS', $this->spin($s, 5)['status']['code']);   // no new paid spin mid-round
        $this->gsc($s, 'play', ['action' => ['name' => 'freespin_init', 'params' => []]]);
        $r = $this->gsc($s, 'play', ['action' => ['name' => 'freespin', 'params' => []]]);
        $this->assertSame(10300, $r['user']['balance']);
        $this->assertSame(250, $r['context']['freespins']['round_win']);
        $this->assertSame(350, $r['context']['freespins']['total_win']);
        $this->assertSame(250, $r['context']['last_win']);
        $this->gsc($s, 'play', ['action' => ['name' => 'freespin', 'params' => []]]);
        $r = $this->gsc($s, 'play', ['action' => ['name' => 'freespin_stop', 'params' => []]]);   // the last: our real balance
        $this->assertSame(10300, $r['user']['balance']);
        $this->assertSame(350, $r['context']['last_win']);
        $this->assertSame(['spin'], $r['context']['actions']);
        $this->assertEquals(103, (float) $player->wallet->fresh()->balance);

        // sync reports the context last served, our balance
        $sync = $this->gsc($s, 'sync');
        $this->assertSame('freespin_stop', $sync['context']['last_action']);
        $this->assertSame(10300, $sync['user']['balance']);

        // round over: a non-spin action is refused, the next spin is a new paid one
        $this->assertSame('ACTION_NOT_ALLOWED', $this->gsc($s, 'play', ['action' => ['name' => 'freespin', 'params' => []]])['status']['code']);
        Cache::put('booongo:force:test_bng_html', $round->id, 60);
        $this->spin($s, 1);
        $this->assertEquals(103.6, (float) $player->wallet->fresh()->balance);   // 103 − 0.10 + 0.70
    }

    public function test_last_win_is_the_players_own_not_the_recorded_sessions(): void
    {
        [, , $s] = $this->booongoGame();
        // a losing spin whose recording still shows that session's earlier win of 35
        $lose = BooongoRound::create(['game_key' => 'test_bng_html', 'bet_cents' => 10, 'win_cents' => 0, 'start_credit' => 10000,
            'steps' => [$this->step('spin', 'spins', 0, 0, 35, 9990, ['spin'], true)]]);
        $win = BooongoRound::create(['game_key' => 'test_bng_html', 'bet_cents' => 10, 'win_cents' => 15, 'start_credit' => 10000,
            'steps' => [$this->step('spin', 'spins', 15, 15, 15, 10005, ['spin'], true)]]);
        $this->gsc($s, 'start');

        Cache::put('booongo:force:test_bng_html', $lose->id, 60);
        $this->assertSame(0, $this->spin($s, 2)['context']['last_win']);
        Cache::put('booongo:force:test_bng_html', $win->id, 60);
        $this->assertSame(30, $this->spin($s, 2)['context']['last_win']);
        Cache::put('booongo:force:test_bng_html', $lose->id, 60);
        $this->assertSame(30, $this->spin($s, 2)['context']['last_win']);
    }

    public function test_a_pick_puts_the_recorded_outcome_under_the_players_box(): void
    {
        [, , $s] = $this->booongoGame();
        $pick = $this->step('freespin_pick', 'freespins', 0, 0, 0, 9990, ['freespin']);
        $pick['context']['freespins'] += ['freespin_pick' => [1, 5, 4], 'freespin_mode' => 5];   // recorded: box 1 → mode 5
        $round = BooongoRound::create(['game_key' => 'test_bng_html', 'bet_cents' => 10, 'win_cents' => 0, 'feature' => true, 'start_credit' => 10000, 'steps' => [
            $this->step('spin', 'spins', 0, 0, 0, 9990, ['freespin_init']),
            $this->step('freespin_init', 'freespins', 0, 0, 0, 9990, ['freespin_pick']),
            $pick,
            $this->step('freespin_stop', 'spins', 0, 0, 0, 9990, ['spin'], true),
        ]]);
        Cache::put('booongo:force:test_bng_html', $round->id, 60);
        $this->gsc($s, 'start');
        $this->spin($s, 1);
        $this->gsc($s, 'play', ['action' => ['name' => 'freespin_init', 'params' => []]]);

        $fs = $this->gsc($s, 'play', ['action' => ['name' => 'freespin_pick', 'params' => ['pick' => 2]]])['context']['freespins'];
        $this->assertSame([1, 4, 5], $fs['freespin_pick']);
        $this->assertSame(5, $fs['freespin_mode']);
    }

    public function test_bad_bets_and_low_credit_are_refused(): void
    {
        [, $player, $s] = $this->booongoGame();
        BooongoRound::create(['game_key' => 'test_bng_html', 'bet_cents' => 10, 'win_cents' => 0, 'start_credit' => 10000,
            'steps' => [$this->step('spin', 'spins', 0, 0, 0, 9990, ['spin'], true)]]);
        $this->gsc($s, 'start');

        $this->assertSame('WRONG_BET', $this->spin($s, 3)['status']['code']);
        $this->assertSame('WRONG_BET', $this->spin($s, 10)['status']['code']);   // not one of the game's stakes
        $this->assertSame('WRONG_BET', $this->spin($s, 1, 5)['status']['code']);
        $player->wallet->update(['balance' => 0.05]);
        $this->assertSame('FUNDS_EXCEED', $this->spin($s, 1)['status']['code']);
        $this->assertEquals(0.05, (float) $player->wallet->fresh()->balance);
    }

    public function test_an_unknown_session_and_runner_logs(): void
    {
        $this->postJson('/booongo/nope/engine?gsc=login', ['command' => 'login', 'request_id' => 'x'])
            ->assertOk()->assertJson(['status' => ['code' => 'SESSION_EXPIRED']]);
        $this->postJson('/booongo/nope/engine', [['type' => 'info', 'msg' => 'GAME_CLIENT_LOADED']])
            ->assertOk()->assertJson(['status' => ['code' => 'OK']]);
    }

    public function test_a_round_runs_on_until_the_server_offers_a_spin(): void
    {
        $this->assertTrue(BooongoLiveClient::idle(['context' => ['actions' => ['spin'], 'round_finished' => true]]));
        $this->assertFalse(BooongoLiveClient::idle(['context' => ['actions' => ['freespin_init'], 'round_finished' => false]]));
        // a hold-and-win bonus is "finished" before its stop action
        $this->assertFalse(BooongoLiveClient::idle(['context' => ['actions' => ['bonus_spins_stop'], 'round_finished' => true]]));
    }
}
