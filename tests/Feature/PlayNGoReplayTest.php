<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\PlayNGoRound;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Play'n GO line game (Book of Dead) served from recorded rounds: the
 * recorded coins × lines are fixed, the coin value is the player's; every
 * command of a feature round is answered from its recording, in order.
 */
class PlayNGoReplayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: GameSession} */
    private function bookOfDead(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'P', 'slug' => 'p', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create(['code' => 'TestBookOfDead', 'title' => 'T', 'engine' => 'internal', 'device' => 'both',
            'bank_type' => 'slots', 'default_denomination' => 1, 'client_protocol' => ClientProtocol::PlayNGo,
            'bonus_config' => ['playngo' => ['gid' => 'bookofdead', 'game_id' => 310, 'bet_coins' => 50, 'spin_coins' => 5, 'spin_lines' => 10, 'record_coin' => 10, 'init_lines' => ['56 0']]]]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.5, 1, 5, 10], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);

        return [$player, GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'png'.uniqid(), 'is_active' => true])];
    }

    /** @return list<string> reply lines */
    private function send(GameSession $s, string $line): array
    {
        $body = (string) $this->call('POST', "/playngo/{$s->token}/server", [], [], [], ['CONTENT_TYPE' => 'text/plain'], "d=1\r\nx\r\n{$line}\r\n")->assertOk()->getContent();

        return array_values(array_filter(preg_split('/\r?\n/', (string) preg_replace('/^d=/', '', $body)) ?: [], fn ($l) => $l !== ''));
    }

    public function test_only_the_recorded_coins_and_lines_are_played_at_the_players_coin_value(): void
    {
        [$player, $session] = $this->bookOfDead();
        PlayNGoRound::create(['game_key' => 'bookofdead', 'bet_coins' => 50, 'win_coins' => 0, 'created_at' => now(),
            'steps' => [['sent' => '1 5 10 10 1', 'reply' => "1 5 10 10 4 2 3\r\n3 1 0 0 0 1\r\n6 0\r\n52 2499500 0 0\r\n"]]]);

        $this->assertSame(['111 "Invalid bet"'], $this->send($session, '1 3 10 2 1'));   // 3 coins: not recorded
        $this->assertSame(['111 "Invalid bet"'], $this->send($session, '1 5 9 2 1'));    // 9 lines: not recorded
        $this->assertEqualsWithDelta(100, (float) $player->wallet->fresh()->balance, 0.001);

        $reply = $this->send($session, '1 5 10 2 1');   // 5 × 10 coins at 2¢ = 1.00
        $this->assertStringStartsWith('1 5 10 2 ', $reply[0]);
        $this->assertEqualsWithDelta(99, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_a_free_spins_round_answers_each_command_from_its_recording(): void
    {
        [$player, $session] = $this->bookOfDead();
        // Book of Dead's feature, as captured: the spin, `2 6` with all free spins, the client's
        // `7 n` reports (83 n back, 113 at the end), then the collect
        PlayNGoRound::create(['game_key' => 'bookofdead', 'bet_coins' => 50, 'win_coins' => 2050, 'feature' => true, 'created_at' => now(), 'steps' => [
            ['sent' => '1 5 10 10 1', 'reply' => "1 5 10 10 4 1 9\r\n2 0 0 9 3 0\r\n2 5 1\r\n"],
            ['sent' => '2 6', 'reply' => "2 6\r\n3 0 150 1500 1 1\r\n1 5 10 10 4 3 5\r\n3 1 2050 20500 0 1\r\n"],
            ['sent' => '7 0', 'reply' => "83 0\r\n"],
            ['sent' => '7 1', 'reply' => "113\r\n"],
            ['sent' => '4 0', 'reply' => "5 2050\r\n6 20500\r\n52 2519500 0 0\r\n"],
        ]]);

        $this->assertSame('2 5 1', last($this->send($session, '1 5 10 2 1')));
        $this->assertContains('3 1 2050 4100 0 1', $this->send($session, '2 6'));   // 2050 coins at 2¢
        $this->assertSame(['83 0'], $this->send($session, '7 0'));
        $this->assertSame(['113'], $this->send($session, '7 1'));
        $collect = $this->send($session, '4 0');
        $this->assertSame(['5 2050', '6 4100'], array_slice($collect, 0, 2));
        $this->assertEqualsWithDelta(100 - 1 + 41, (float) $player->wallet->fresh()->balance, 0.001);
        $this->assertSame(['83 5'], $this->send($session, '7 5'));   // no round in play: Play'n GO's echo
    }

    public function test_a_free_spin_reported_before_its_boards_does_not_skip_them(): void
    {
        // Rise of Olympus: clearing the grid brings a free spin inside the first `2 6`;
        // the client reports it (`7 0`) before asking for its boards (`2 6`) — the
        // recording has the reports after. Once this jumped ahead and the game froze.
        [$player, $session] = $this->bookOfDead();
        PlayNGoRound::create(['game_key' => 'bookofdead', 'bet_coins' => 50, 'win_coins' => 574, 'feature' => true, 'created_at' => now(), 'steps' => [
            ['sent' => '1 5 10 10 1', 'reply' => "1 5 10 10 3 6 3\r\n2 5 1\r\n"],
            ['sent' => '2 6', 'reply' => "2 6\r\n3 0 95 950 1 1\r\n1 5 10 10 4 6 0\r\n2 5 1\r\n"],
            ['sent' => '2 6', 'reply' => "2 6\r\n3 0 574 5740 0 1\r\n"],
            ['sent' => '7 0', 'reply' => "83 0\r\n"],
            ['sent' => '7 1', 'reply' => "83 1\r\n"],
            ['sent' => '4 0', 'reply' => "5 574\r\n6 5740\r\n52 2490030 0 0\r\n"],
        ]]);

        $this->send($session, '1 5 10 2 1');
        $this->assertSame('2 5 1', last($this->send($session, '2 6')));
        $this->assertSame(['83 0'], $this->send($session, '7 0'));             // reported early …
        $this->assertContains('3 0 574 1148 0 1', $this->send($session, '2 6'));   // … the boards still come
        $this->assertSame(['83 1'], $this->send($session, '7 1'));
        $this->assertSame(['5 574', '6 1148'], array_slice($this->send($session, '4 0'), 0, 2));
        $this->assertEqualsWithDelta(100 - 1 + 11.48, (float) $player->wallet->fresh()->balance, 0.001);
    }

    public function test_the_games_own_init_state_is_sent_as_play_n_go_sends_it(): void
    {
        // Rise of Olympus fails on its first god award (and freezes) without `56 0`
        [, $session] = $this->bookOfDead();
        $init = $this->send($session, '104 310 0 x');
        $this->assertContains('56 0', $init);
        $this->assertContains('60 96 0 0 1', $init);   // the shop's return, not a small library's
        $this->assertSame(['56 0'], \App\Services\GamePlay\PlayNGo\PlayNGoLiveClient::initExtras("d=104 1\r\n54 2 1 2 1\r\n52 100 0 0\r\n83 0\r\n56 0\r\n91 1 \"x\"\r\n109\r\n"));
    }
}
