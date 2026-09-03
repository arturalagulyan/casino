<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The legacy VanguardLTE `slotEvent` HTTP protocol — one generic handler shared
 * by every Novomatic / Greentube game. The template holds the maths; the
 * `slot_event` client protocol supplies the wire format. No per-game code.
 *
 * The formatter reads symbol names + cosmetic config from the mounted legacy
 * mirror when present; these tests deliberately use a code that isn't in the
 * mirror, so they exercise the pure DB-driven fallback (SYM_<n> names).
 */
class SlotEventProtocolTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: Game, 2: User} */
    protected function slotEventGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Novo', 'slug' => 'novo', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 94, 'max_win_multiplier' => 500, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $tpl = GameTemplate::create([
            'code' => 'TestNovoSlot', 'title' => 'Test Novo Slot',
            'engine' => 'internal', 'client_protocol' => ClientProtocol::SlotEvent,
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 8,
            'symbols' => [0, 1, 2, 3, 4, 5, 6, 7],
            'wild_symbol' => 0, 'scatter_symbol' => 7,
            'min_match' => 2, 'has_free_spins' => true, 'has_gamble' => true,
            'gamble_win_chance' => 2, 'gamble_type' => 1, 'volatility' => 'medium',
            'free_spins_table' => [0, 0, 0, 10, 15, 20], 'free_spins_multiplier' => 3,
            'paytable' => [
                0 => [0, 0, 10, 100, 500, 2500],
                1 => [0, 0, 5, 40, 200, 1000],
                2 => [0, 0, 5, 40, 200, 1000],
                3 => [0, 0, 0, 20, 80, 400],
                4 => [0, 0, 0, 20, 80, 400],
                5 => [0, 0, 0, 10, 40, 150],
                6 => [0, 0, 0, 10, 40, 150],
                7 => [0, 0, 0, 0, 0, 0],
            ],
            'reel_strips' => [
                'reelStrip1' => [1, 3, 5, 2, 4, 6, 0, 1, 7, 3, 5, 2, 6, 4, 1, 3, 5, 2, 4, 6],
                'reelStrip2' => [2, 4, 6, 1, 3, 5, 0, 2, 7, 4, 6, 1, 5, 3, 2, 4, 6, 1, 3, 5],
                'reelStrip3' => [3, 5, 1, 4, 6, 2, 0, 3, 7, 5, 1, 4, 6, 2, 3, 5, 1, 4, 6, 2],
                'reelStrip4' => [4, 6, 2, 5, 1, 3, 0, 4, 7, 6, 2, 5, 1, 3, 4, 6, 2, 5, 1, 3],
                'reelStrip5' => [5, 1, 3, 6, 2, 4, 0, 5, 7, 1, 3, 6, 2, 4, 5, 1, 3, 6, 2, 4],
            ],
            'paylines' => array_fill(0, 10, [1, 1, 1, 1, 1]),
        ]);

        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [1, 2, 5, 10, 20],
            'rtp_percent' => 94, 'max_win_multiplier' => 500,
        ]);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 10000]);

        return [$shop, $game, $player];
    }

    protected function openSession(User $player, Game $game): GameSession
    {
        return GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id,
            'token' => 'se-'.uniqid(), 'is_active' => true,
        ]);
    }

    protected function event(GameSession $session, array $body): array
    {
        return $this->postJson(
            "/game/{$session->game->template->code}/server?sessionId={$session->token}",
            $body,
        )->assertOk()->json();
    }

    protected function walletDelta(User $player): float
    {
        return $player->transactions()->get()->sum(
            fn ($t) => ($t->direction->value === 'debit' ? -1 : 1) * (float) $t->amount,
        );
    }

    public function test_bad_session_is_rejected(): void
    {
        [, $game] = $this->slotEventGame();

        $this->postJson("/game/{$game->template->code}/server?sessionId=nope", ['slotEvent' => 'getSettings'])
            ->assertStatus(403);
    }

    public function test_get_settings_returns_the_full_slot_settings_shape(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);

        $res = $this->event($session, ['slotEvent' => 'getSettings']);

        $this->assertSame('getSettings', $res['responseEvent']);
        $this->assertSame('CREDIT', $res['slotLanguage']['counterCredit']);   // real UI labels, not {}
        $sr = $res['serverResponse'];
        $this->assertSame('EUR', $sr['slotCurrency']);
        $this->assertSame('TestNovoSlot', $sr['slotId']);
        $this->assertSame(10000, $sr['Balance']);                 // credits at denom 1
        $this->assertSame([1, 2, 5, 10, 20], $sr['Bet']);
        $this->assertCount(8, $sr['SymbolGame']);
        $this->assertArrayHasKey('SYM_0', $sr['Paytable']);        // name-keyed, mirror-less fallback
        $this->assertSame([0, 0, 10, 100, 500, 2500], $sr['Paytable']['SYM_0']);
        $this->assertCount(20, $sr['reelStrip1']);                 // names, from the DB strip
        $this->assertTrue($sr['slotGamble']);
        $this->assertSame(3, $sr['slotFreeMpl']);
        $this->assertArrayHasKey('jack1', $sr['Jackpots']);
    }

    public function test_a_bet_debits_the_wallet_conserves_money_and_records_a_round(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);

        $res = $this->event($session, ['slotEvent' => 'bet', 'slotBet' => 1, 'slotLines' => 10]);

        $this->assertSame('spin', $res['responseEvent']);
        $sr = $res['serverResponse'];
        $this->assertSame(10, $sr['slotLines']);
        $this->assertArrayHasKey('reelsSymbols', $sr);
        $this->assertCount(4, $sr['reelsSymbols']['reel1']);        // 3 visible + legacy 4th

        // Balance in the frame is post-stake / pre-win; afterBalance is the wallet.
        $this->assertGreaterThanOrEqual(0, $sr['totalWin']);
        $this->assertEqualsWithDelta(9990 + $sr['totalWin'], $sr['afterBalance'], 0.001);

        // wallet moved by exactly the ledger transactions
        $this->assertEqualsWithDelta(
            10000 + $this->walletDelta($player),
            (float) $player->wallet->fresh()->balance,
            0.001,
        );

        $round = $player->rounds()->latest('id')->first();
        $this->assertNotNull($round);
        $this->assertSame('10.0000', $round->bet);
    }

    public function test_invalid_bet_state_returns_an_error_frame(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);

        $res = $this->event($session, ['slotEvent' => 'bet', 'slotBet' => 0, 'slotLines' => 10]);

        $this->assertSame('error', $res['responseEvent']);
        $this->assertSame('bet', $res['responseType']);
    }

    public function test_bet_beyond_balance_is_refused_without_debiting(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);
        $player->wallet->update(['balance' => 50]);            // < 200 stake

        $res = $this->event($session, ['slotEvent' => 'bet', 'slotBet' => 20, 'slotLines' => 10]);

        $this->assertSame('error', $res['responseEvent']);
        $this->assertSame(50.0, (float) $player->wallet->fresh()->balance);
        $this->assertSame(0, $player->rounds()->count());
    }

    public function test_free_spin_costs_nothing_and_decrements_the_counter(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);

        // Land on a bank-pick-free-state by seeding the session as if a spin had
        // just granted 10 free games.
        $session->update(['state' => ['features' => [
            'last_bet' => 1.0, 'last_lines' => 10, 'stake' => 10.0,
            'frozen_balance' => 9990.0, 'bonus_win' => 0.0, 'total_win' => 0.0,
            'free_spins_left' => 10, 'free_spins_total' => 10, 'free_spins_used' => 0,
        ]]]);
        $player->wallet->update(['balance' => 9990]);
        $before = (float) $player->wallet->fresh()->balance;

        $res = $this->event($session, ['slotEvent' => 'freespin']);

        $this->assertSame('spin', $res['responseEvent']);
        $this->assertSame('freespin', $res['responseType']);
        $this->assertSame(9, $session->fresh()->state['features']['free_spins_left']);
        $this->assertSame(1, $session->fresh()->state['features']['free_spins_used']);

        // free spins never debit; a win only ever raises the balance
        $this->assertGreaterThanOrEqual($before, (float) $player->wallet->fresh()->balance);
        $this->assertSame('0.0000', $player->rounds()->latest('id')->first()->bet);
    }

    public function test_free_spin_without_a_grant_is_an_error(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);

        $res = $this->event($session, ['slotEvent' => 'freespin']);

        $this->assertSame('error', $res['responseEvent']);
        $this->assertSame('freespin', $res['responseType']);
    }

    public function test_gamble_is_wallet_consistent_and_never_records_a_negative_win(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);
        $player->wallet->update(['balance' => 100000]);

        // spin until a win sits on the table to gamble
        $won = false;
        for ($i = 0; $i < 80; $i++) {
            $sr = $this->event($session, ['slotEvent' => 'bet', 'slotBet' => 2, 'slotLines' => 10])['serverResponse'];
            if (($sr['totalWin'] ?? 0) > 0) {
                $won = true;
                break;
            }
        }

        if ($won) {
            $res = $this->event($session, ['slotEvent' => 'slotGamble', 'gambleChoice' => 'red']);
            $this->assertSame('gambleResult', $res['responseEvent']);
            $this->assertContains($res['serverResponse']['gambleState'], ['win', 'lose']);
        }

        $this->assertSame(0, $player->rounds()->where('win', '<', 0)->count());
        $this->assertEqualsWithDelta(
            100000 + $this->walletDelta($player),
            (float) $player->wallet->fresh()->balance,
            0.01,
        );
    }

    public function test_update_event_replies_with_the_balance_poll_frame(): void
    {
        [, $game, $player] = $this->slotEventGame();
        $session = $this->openSession($player, $game);

        $res = $this->event($session, ['slotEvent' => 'update']);

        $this->assertSame('error', $res['responseEvent']);
        $this->assertSame('update', $res['responseType']);
        $this->assertSame('10000', (string) $res['serverResponse']);
    }
}
