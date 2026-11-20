<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\NovolineRound;
use App\Models\NovolineSetup;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Engine\SlotEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Greentube (Novoline) frames are recorded at the smallest line bet and served
 * at k× it: credit amounts × k, the balance (`S`) ours — the round's payout
 * paid once, at the spin; collects and free games follow, unpaid. A win waiting
 * for its collect may be gambled, red / black, double or nothing.
 */
class NovolineProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const string SEP = "\u{FF}";

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function novolineGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'N', 'slug' => 'n', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestNovo', 'title' => 'Test Novoline', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Novoline, 'default_denomination' => 1,
            'bonus_config' => ['novoline' => ['game_key' => 'test_gt_html', 'folder' => 'test_gt_html', 'lines' => 10, 'per_line' => 1, 'x' => '9',
                'bet_cents' => 10, 'bet_steps' => [1, 2, 5, 10], 'factor' => 100, 'gamble' => true, 'gamble_max' => 5]],
        ]);
        NovolineSetup::create(['game_key' => 'test_gt_html', 'login' => [
            self::f('S10000', 'A1', 'C,100.0,,-1', 'T,0,0,0', 'I00', ':i,5', ':i,10', ':f,4,9', ':m,5,10', ':e,0,0', ':b,9,1,2,5,10,20,50,100,200,500', ':g,5,1000,2500000', 'e,10,1,9', 's,1', 'h,DDHCHDDD'),
            "\x07ERRDESCRIPTION".self::SEP.'SUCCESS'.self::SEP.'CURRENCYFACTOR'.self::SEP.'100.000000',
        ]]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.1, 0.2, 0.5], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'nv'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    private static function f(string ...$fields): string
    {
        return "\x06".implode(self::SEP, $fields);
    }

    /** @return list<string> */
    private function send(GameSession $s, string $frame): array
    {
        return (array) $this->call('POST', "/novoline/{$s->token}/ws", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $frame)->assertOk()->json();
    }

    /** A state frame's field by key (`W`, `f`, `s`…), or its balance for `S`. */
    private static function field(array $frames, string $key): ?string
    {
        $state = (string) collect($frames)->last(fn ($f) => ($f[0] ?? '') === "\x06");
        foreach (explode(self::SEP, substr($state, 1)) as $x) {
            if ($key === 'S' && preg_match('/^S(\d+)$/', $x, $m)) {
                return $m[1];
            }
            if (str_starts_with($x, $key.',')) {
                return substr($x, strlen($key) + 1);
            }
        }

        return null;
    }

    /** A base-game win of 25 credits at a 10-credit stake: spin (pending win), collect. */
    private function baseWin(): NovolineRound
    {
        return NovolineRound::create(['game_key' => 'test_gt_html', 'bet_cents' => 10, 'win_cents' => 25, 'feature' => false,
            'start_credit' => 10000, 'created_at' => now(), 'steps' => [
                ['c' => '30', 'r' => ["%t0\u{FF}q0", "\x1f10", self::f('S9990', 'A1', 'T,1,0,0', 'I10', 'W,25', 'e,10,1,9', 's,11', 'q,1', 'h,DDHCHDDD', 'r,0,5,1,2,3,4,5', 'l,J,4,7,0,0,25,0,0,JJJB-', '')]],
                ['c' => '40', 'r' => ["%t0\u{FF}q0", "\x1f10", self::f('S10015', 'A1', 'T,2,10,25', 'I11', 'e,10,1,9', ':x,25', 's,1', 'r,0,5,1,2,3,4,5', 'l,J,4,7,0,0,25,0,0,JJJB-', '')]],
            ]]);
    }

    public function test_login_pins_the_recorded_line_option_and_offers_only_the_shops_stakes(): void
    {
        [, , $s] = $this->novolineGame();
        $out = $this->send($s, "\x01".'1'.self::SEP.'1000'.self::SEP.'test_gt_html'.self::SEP.'1'.self::SEP.'0');

        $this->assertCount(2, $out);
        $state = explode(self::SEP, substr($out[0], 1));
        $this->assertSame('S10000', $state[0]);
        $this->assertSame([':i,10'], array_values(array_filter($state, fn ($x) => str_starts_with($x, ':i,'))));
        $this->assertContains(':f,9', $state);
        $this->assertContains(':m,10', $state);
        $this->assertContains(':e,0', $state);
        $this->assertContains(':b,3,1,2,5', $state);   // 0.10 / 0.20 / 0.50 — the shop has no 1.00
        $this->assertStringStartsWith("\x07", $out[1]);
        $this->assertSame([], $this->send($s, '#0'));
    }

    public function test_a_spin_at_twice_the_line_bet_scales_the_win_and_pays_it_once(): void
    {
        [, $player, $s] = $this->novolineGame();
        $this->baseWin();
        $this->assertSame([], $this->send($s, '22'.self::SEP.'10'.self::SEP.'9'));

        $spin = $this->send($s, '30');
        $this->assertContains("\x1f20", $spin);
        $this->assertSame('50', self::field($spin, 'W'));
        $this->assertSame('J,4,7,0,0,50,0,0,JJJB-', self::field($spin, 'l'));
        $this->assertSame('10,2,9', self::field($spin, 'e'));
        $this->assertSame('9980', self::field($spin, 'S'));   // the win shows outside the balance until collected
        $this->assertEqualsWithDelta(100.30, (float) $player->wallet->fresh()->balance, 0.001);

        $collect = $this->send($s, '40');
        $this->assertSame('10030', self::field($collect, 'S'));
        $this->assertSame('1', self::field($collect, 's'));
        $this->assertSame('50', self::field($collect, ':x'));
        $this->assertEqualsWithDelta(100.30, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_the_last_win_gambled_twice_won_then_collected(): void
    {
        [, $player, $s] = $this->novolineGame();
        $this->baseWin();
        $this->mock(SlotEngine::class, fn ($m) => $m->shouldReceive('gamble')->andReturn(['won' => true]));
        $this->send($s, '30');

        $g = $this->send($s, '9');
        $this->assertSame('3', self::field($g, 's'));
        $this->assertStringContainsString('q,50,0'.self::SEP.'q,100,0', $g[1]);
        $won = $this->send($s, '5r');
        $this->assertSame('50', self::field($won, 'W'));
        $this->assertSame('9990', self::field($won, 'S'));
        $this->assertSame('25,0', self::field($won, 'g'));
        $this->assertMatchesRegularExpression('/h,DHCHDDD[DH]/', $won[1]);   // the card turned up is red
        $this->send($s, '5b');
        $this->assertEqualsWithDelta(100.90, (float) $player->wallet->fresh()->balance, 0.001);   // 100 − 0.10 + 0.25 + 0.25 + 0.50

        $taken = $this->send($s, '40');
        $this->assertSame('1', self::field($taken, 's'));
        $this->assertSame('100', self::field($taken, ':x'));
        $this->assertSame('10090', self::field($taken, 'S'));
        $this->assertNull(self::field($taken, 'W'));
    }

    public function test_the_last_win_gambled_and_lost(): void
    {
        [, $player, $s] = $this->novolineGame();
        $this->baseWin();
        $this->mock(SlotEngine::class, fn ($m) => $m->shouldReceive('gamble')->andReturn(['won' => false]));
        $this->send($s, '30');
        $this->send($s, '9');

        $lost = $this->send($s, '5r');
        $this->assertSame('1', self::field($lost, 's'));
        $this->assertNull(self::field($lost, 'W'));
        $this->assertNull(self::field($lost, 'l'));
        $this->assertSame('9990', self::field($lost, 'S'));
        $this->assertEqualsWithDelta(99.90, (float) $player->wallet->fresh()->balance, 0.001);
        $this->assertSame([], $this->send($s, '40'));   // nothing left to collect
    }

    public function test_a_free_game_win_gambled_away_moves_the_rest_of_the_round(): void
    {
        [, $player, $s] = $this->novolineGame();
        // scatter 20 → collect → 10 free games: one win of 10 (gambled) → the last, no win
        NovolineRound::create(['game_key' => 'test_gt_html', 'bet_cents' => 10, 'win_cents' => 30, 'feature' => true,
            'start_credit' => 10000, 'created_at' => now(), 'steps' => [
                ['c' => '30', 'r' => ["\x1f10", self::f('S9990', 'I10', 'W,20', 'f,10,0,1,1,4,0,20', 's,11', 'q,1', 'h,DDHCHDDD', 'c,B,3,10,0,20,0,,001041', '')]],
                ['c' => '40', 'r' => [self::f('S10010', 'I11', 'f,10,0,1,1,4,20,20', ':x,20', 's,5', '')]],
                ['c' => '60', 'r' => ["\x1f0", self::f('S10010', 'I10', 'W,10', 'f,10,1,1,1,4,20,30', 's,11', 'q,1', 'h,DDHCHDDD', 'l,J,3,1,0,0,10,0,0,JJJ--', '')]],
                ['c' => '40', 'r' => [self::f('S10020', 'I11', 'f,10,1,1,1,4,30,30', ':x,10', 's,5', '')]],
                ['c' => '60', 'r' => ["\x1f0", self::f('S10020', 'I11', 'f,10,10,1,1,0,30,30', 's,1', 'h,DDHCHDDD', '')]],
            ]]);
        $this->mock(SlotEngine::class, fn ($m) => $m->shouldReceive('gamble')->andReturn(['won' => false]));
        $this->send($s, '30');
        $this->send($s, '40');
        $this->assertSame('11', self::field($this->send($s, '60'), 's'));
        $this->send($s, '9');

        $lost = $this->send($s, '5r');
        $this->assertSame('5', self::field($lost, 's'));   // the free games go on
        $this->assertSame('0', self::field($lost, 'W'));
        $this->assertSame('10,1,1,1,4,20,20', self::field($lost, 'f'));
        $this->assertSame('10010', self::field($lost, 'S'));
        $this->assertEqualsWithDelta(100.10, (float) $player->wallet->fresh()->balance, 0.001);   // 100 − 0.10 + 0.30 − 0.10

        $end = $this->send($s, '60');   // the recorded collect is skipped: the next free game
        $this->assertSame('1', self::field($end, 's'));
        $this->assertSame('10,10,1,1,0,20,20', self::field($end, 'f'));
        $this->assertSame('10010', self::field($end, 'S'));
    }

    public function test_a_pick_plays_on_as_recorded_showing_the_players_side_and_extra_wins_scale(): void
    {
        [, $player, $s] = $this->novolineGame();
        // Wild Horses: a scatter rests in 15 until a side is picked (`:0` recorded); Quest for Gold's extra win `m`
        NovolineRound::create(['game_key' => 'test_gt_html', 'bet_cents' => 10, 'win_cents' => 15, 'feature' => true,
            'start_credit' => 10000, 'created_at' => now(), 'steps' => [
                ['c' => '30', 'r' => ["\x1f10", self::f('S9990', 'I11', 'f,10,0,1,1,1,0,0', 's,15', 'c,#,3,10,0,0,0,,122233', 'v,FakeFeatureStart')]],
                ['c' => ':0', 'r' => [self::f('S9990', 'I11', 'f,10,0,1,1,1,0,0', 's,5', '', 'x,2,0,0:0:0:0')]],
                ['c' => '60', 'r' => ["\x1f0", self::f('S9990', 'I10', 'W,15', 'f,10,10,1,1,1,0,15', 's,11', 'l,1,3,20,0,0,10,0,0,W11--', 'm,5,0', '', 'x,2,0,0:2:0:0')]],
                ['c' => '40', 'r' => ["\x1f15", self::f('S10005', 'I11', 'f,10,10,1,1,1,15,15', ':x,15', 's,1', '', 'x,2,0,0:2:0:0')]],
            ]]);
        $this->send($s, '25'.self::SEP.'10'.self::SEP.'9');   // 5× the line bet

        $this->assertSame('15', self::field($this->send($s, '30'), 's'));
        $this->assertSame('15', self::field($this->send($s, ':-1'), 's'));   // the pointer over a choice: nothing moves
        $picked = $this->send($s, ':1');
        $this->assertSame('5', self::field($picked, 's'));
        $this->assertSame('2,1,0:0:0:0', self::field($picked, 'x'));
        $again = $this->send($s, ':0');   // a pick once the feature runs: shown, nothing moves
        $this->assertSame('5', self::field($again, 's'));
        $this->assertSame('2,0,0:0:0:0', self::field($again, 'x'));
        $free = $this->send($s, '60');
        $this->assertSame('75', self::field($free, 'W'));
        $this->assertSame('25,0', self::field($free, 'm'));
        $this->assertSame('2,1,0:2:0:0', self::field($free, 'x'));   // the pick that started the feature
        $end = $this->send($s, '40');
        $this->assertSame('1', self::field($end, 's'));
        $this->assertSame('10025', self::field($end, 'S'));
        $this->assertEqualsWithDelta(100.25, (float) $player->wallet->fresh()->balance, 0.001);   // 100 − 0.50 + 0.75
    }

    public function test_an_unknown_session_is_closed(): void
    {
        $this->call('POST', '/novoline/nope/ws', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '30')->assertStatus(410);
    }
}
