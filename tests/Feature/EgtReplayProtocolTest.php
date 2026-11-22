<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\EgtRound;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * EGT replies are recorded at the smallest bet and served at the player's:
 * money fields × bet / recorded bet; the balance a reply shows is ours, the
 * win left out until collected (our ledger pays it at the paid spin); free
 * spins follow unpaid; gamble is ours.
 */
class EgtReplayProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const int GIN = 801;

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function egtGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestEgt', 'title' => 'Test EGT', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Egt, 'default_denomination' => 1,
            'bonus_config' => ['egt' => ['gin' => self::GIN, 'record_bet' => 5, 'bets' => [5, 10, 20], 'languages' => ['en'],
                'login' => ['playerName' => 'x', 'balance' => 10000, 'currency' => 'NAN', 'languages' => ['en'], 'groups' => ['all'],
                    'complex' => ['BHJSlot' => [['gameIdentificationNumber' => self::GIN, 'recovery' => 'norecovery', 'gameName' => 'Test']]]],
                'settings' => ['complex' => ['bets' => [5, 10, 20], 'defaultBetIndex' => 0, 'rtp' => '96.44', 'paytableCoef' => [], 'jackpot' => false]],
                'subscribe' => ['complex' => ['currentState' => ['state' => 'idle', 'bet' => 5, 'reels' => [0, 1, 2], 'gambles' => 0]]],
            ]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.05, 0.1, 0.2], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'egt'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /** @return array<string, mixed> the one reply frame, decoded */
    private function frame(GameSession $s, array|string $frame): array
    {
        $frames = $this->call('POST', "/egt/{$s->token}/ws", [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            is_string($frame) ? $frame : json_encode($frame + ['sessionKey' => $s->token, 'messageId' => 'm1']))->assertOk()->json();
        $this->assertCount(1, $frames);

        return (array) json_decode($frames[0], true);
    }

    private function bet(GameSession $s, array $bet): array
    {
        return $this->frame($s, ['command' => 'bet', 'gameIdentificationNumber' => self::GIN, 'bet' => $bet, 'gameNumber' => -1]);
    }

    /** A recorded free-spins round at bet 5 (start balance 10000): scatter 50, 2 free spins (win 20, total 70). */
    private function freeSpinRound(): EgtRound
    {
        return EgtRound::create(['gin' => self::GIN, 'bet_cents' => 5, 'win_cents' => 70, 'feature' => true, 'start_balance' => 10000, 'steps' => [
            ['complex' => ['reels' => [1], 'lines' => [], 'scatters' => [['scatterName' => 10, 'cells' => [0, 1], 'winAmount' => 50]], 'gambles' => 3, 'freespins' => 2, 'gameCommand' => 'bet'],
                'state' => 'freespin', 'winAmount' => 50, 'balance' => 9995],
            ['complex' => ['reels' => [2], 'lines' => [['line' => 3, 'card' => 4, 'cells' => [0, 1], 'winAmount' => 20, 'multiplier' => 3]], 'gambles' => 0, 'freespins' => 0, 'gameCommand' => 'bet'],
                'state' => 'freespin', 'winAmount' => 70, 'balance' => 9995],
            ['complex' => ['reels' => [3], 'lines' => [], 'scatters' => [], 'gambles' => 2, 'freespins' => 0, 'gameCommand' => 'bet'],
                'state' => 'gamble', 'winAmount' => 70],
        ], 'created_at' => now()]);
    }

    public function test_login_settings_subscribe_carry_our_balance_currency_and_bets(): void
    {
        [, , $s] = $this->egtGame();
        $login = $this->frame($s, ['command' => 'login', 'gameIdentificationNumber' => self::GIN]);
        $this->assertSame('success', $login['msg']);
        $this->assertSame(10000, $login['balance']);
        $this->assertSame('EUR', $login['currency']);
        $this->assertSame('m1', $login['messageId']);
        $this->assertCount(3, explode('.', $login['playerIdToken']));
        $this->assertArrayHasKey('BHJSlot', $login['complex']);

        $settings = $this->frame($s, ['command' => 'settings', 'gameIdentificationNumber' => self::GIN, 'gameNumber' => -1]);
        $this->assertSame([5, 10, 20], $settings['complex']['bets']);

        $sub = $this->frame($s, ['command' => 'subscribe', 'gameIdentificationNumber' => self::GIN, 'gameNumber' => -1]);
        $this->assertSame('idle', $sub['complex']['currentState']['state']);
        $this->assertSame(5, $sub['complex']['currentState']['bet']);

        $this->assertSame('ping', $this->frame($s, 'ping')['command']);
    }

    public function test_a_free_spin_round_is_served_scaled_paid_once_and_collected(): void
    {
        [, $player, $s] = $this->egtGame();
        $round = $this->freeSpinRound();
        Cache::put('egt:force:'.self::GIN, $round->id, 60);

        // bet 20 = 4 × the recorded 5
        $r1 = $this->bet($s, ['bet' => 20, 'betMultiplier' => 1, 'bonus' => false, 'gameCommand' => 'bet', 'featureId' => 0]);
        $this->assertSame('freespin', $r1['state']);
        $this->assertSame(200, $r1['winAmount']);
        $this->assertSame(200, $r1['complex']['scatters'][0]['winAmount']);
        $this->assertSame(10, $r1['complex']['scatters'][0]['scatterName']);
        $this->assertSame(9980, $r1['balance']);   // the bet taken, the win not shown yet
        $this->assertEqualsWithDelta(100 - 0.2 + 2.8, (float) $player->wallet->fresh()->balance, 0.001);   // paid at once

        $r2 = $this->bet($s, ['bet' => 20, 'betMultiplier' => 1, 'bonus' => true, 'gameCommand' => 'bet', 'featureId' => 0]);
        $this->assertSame(280, $r2['winAmount']);
        $this->assertSame(80, $r2['complex']['lines'][0]['winAmount']);
        $this->assertSame(3, $r2['complex']['lines'][0]['multiplier']);
        $this->assertSame(4, $r2['complex']['lines'][0]['card']);
        $this->assertSame(9980, $r2['balance']);

        $r3 = $this->bet($s, ['bet' => 20, 'betMultiplier' => 1, 'bonus' => true, 'gameCommand' => 'bet', 'featureId' => 0]);
        $this->assertSame('gamble', $r3['state']);
        $this->assertSame(280, $r3['winAmount']);
        $this->assertSame(9980, $r3['balance']);

        $c = $this->bet($s, ['gameCommand' => 'collect']);
        $this->assertSame('idle', $c['state']);
        $this->assertSame(280, $c['winAmount']);
        $this->assertSame(10260, $c['balance']);
        $this->assertEqualsWithDelta(102.6, (float) $player->wallet->fresh()->balance, 0.001);   // no second payment
    }

    public function test_gamble_doubles_or_loses_the_win(): void
    {
        [, $player, $s] = $this->egtGame();
        $round = EgtRound::create(['gin' => self::GIN, 'bet_cents' => 5, 'win_cents' => 10, 'feature' => false, 'start_balance' => 10000, 'steps' => [
            ['complex' => ['reels' => [1], 'lines' => [['line' => 0, 'winAmount' => 10]], 'gambles' => 5, 'gameCommand' => 'bet'], 'state' => 'gamble', 'winAmount' => 10, 'balance' => 9995],
        ], 'created_at' => now()]);
        Cache::put('egt:force:'.self::GIN, $round->id, 60);
        $this->bet($s, ['bet' => 5, 'betMultiplier' => 1, 'bonus' => false, 'gameCommand' => 'bet', 'featureId' => 0]);
        $this->assertEqualsWithDelta(100.05, (float) $player->wallet->fresh()->balance, 0.001);

        $g = $this->bet($s, ['color' => 1, 'gameCommand' => 'gamble']);
        $this->assertSame(4, $g['complex']['gambles']);
        if ($g['state'] === 'gamble') {   // won: black card, 20 to win, 0.10 more paid
            $this->assertContains($g['complex']['card'], [0, 3]);
            $this->assertSame(20, $g['winAmount']);
            $this->assertEqualsWithDelta(100.15, (float) $player->wallet->fresh()->balance, 0.001);
            $this->assertSame(20, $this->bet($s, ['gameCommand' => 'collect'])['winAmount']);
        } else {   // lost: red card, the win taken back
            $this->assertSame('idle', $g['state']);
            $this->assertContains($g['complex']['card'], [1, 2]);
            $this->assertSame(0, $g['winAmount']);
            $this->assertEqualsWithDelta(99.95, (float) $player->wallet->fresh()->balance, 0.001);
            $this->assertSame(9995, $g['balance']);
            $this->assertSame('failure', $this->bet($s, ['color' => 1, 'gameCommand' => 'gamble'])['msg']);   // nothing left to gamble
        }
    }

    public function test_a_bet_not_offered_is_refused(): void
    {
        [, $player, $s] = $this->egtGame();
        $r = $this->bet($s, ['bet' => 7, 'betMultiplier' => 1, 'bonus' => false, 'gameCommand' => 'bet', 'featureId' => 0]);
        $this->assertSame('failure', $r['msg']);
        $this->assertEqualsWithDelta(100, (float) $player->wallet->fresh()->balance, 0.001);
    }
}
