<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\MerkurRound;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Merkur\MerkurLiveClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Merkur (Edict) replies are recorded at the smallest total stake (wager w
 * per line) and served at the player's: money fields × W/w, the depot ours —
 * the round's win paid once, at the paid PLAY; free games follow, unpaid;
 * the card gamble is ours.
 */
class MerkurProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const string CMD = '/services/gaming/5/v2/gateway/casinos/whow-fm/v2/templates/1/money/commands/commands';

    private function init(): array
    {
        return ['mainGameResult' => ['winnings' => [], 'parameters' => ['CLIENT_SETTINGS' => '{"coin":1}'], 'freeGameRound' => 0, 'freeGamesTotal' => 0,
            'reels' => [['visibleSymbolCount' => 3, 'symbols' => [1, 2, 3, 4]]]],
            'nextGameActions' => [['id' => 'PLAY', 'minTotalWager' => 5, 'maxTotalWager' => 2500]],
            'accounting' => ['debit' => 0, 'credit' => 0], 'uncommittedWinSum' => 0, 'lastWagerSum' => 5, 'responseType' => 'INIT',
            'payTable' => ['groups' => []], 'translations' => ['x' => 'y'], 'coins' => [5, 10, 25],
            'wagerPositionSets' => [['wagerPositionIds' => [1, 2, 3, 4, 5], 'type' => 'FIXED']], 'nextGameFlowName' => 'MAIN_GAME'];
    }

    /** @return array{0: Game, 1: User, 2: GameSession} */
    private function merkurGame(bool $split = true): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 96, 'max_win_multiplier' => 5000, 'player_limit' => 100000]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $tpl = GameTemplate::create([
            'code' => 'TestMerkur', 'title' => 'Test Merkur', 'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots',
            'client_protocol' => ClientProtocol::Merkur, 'default_denomination' => 1,
            'bonus_config' => ['merkur' => ['game_key' => 'test_mk_html', 'record_wager' => 1, 'split_coins' => $split,
                'positions' => [1, 2, 3, 4, 5], 'stakes' => [0.05, 0.1, 0.25], 'languages' => ['en'], 'init' => $this->init(),
                'launcher' => ['templateId' => 1, 'launchURL' => '/mk/test/html/index-mobile.html',
                    'gameClientConfiguration' => [['name' => 'ENABLE_RISK_LADDER', 'value' => 'true']]],
                'domain' => ['name' => 'whow-fm', 'currencyDisplayInfo' => ['currencyCode' => '']]]],
        ]);
        $game = Game::create(['shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.05, 0.1, 0.25], 'rtp_percent' => 96, 'max_win_multiplier' => 5000]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 100]);
        $session = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'mk'.uniqid(), 'is_active' => true]);

        return [$game, $player, $session];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} coreData, decoded gameData
     */
    private function command(GameSession $s, array $gameData, int $status = 200): array
    {
        $r = $this->call('POST', "/merkur/{$s->token}/engine?".self::CMD, [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['de.edict.eoc.gaming.comm.GameSessionRequestDTO' => ['command' => 'command', 'gameData' => json_encode($gameData), 'gameSessionUuid' => 'uuid']]));
        $r->assertStatus($status);
        $json = (array) $r->json();
        $dto = $json['de.edict.eoc.gaming.comm.GameClientActionResponseDTO'] ?? $json;

        return [(array) ($dto['coreData'] ?? []), isset($dto['gameData']) ? (array) json_decode($dto['gameData'], true) : (array) $dto];
    }

    /** @return array<string, mixed> */
    private function play(int $wager): array
    {
        return ['gameActionId' => 'PLAY', 'wagers' => array_map(fn ($p) => ['wager' => $wager, 'wagerPositionId' => $p], [1, 2, 3, 4, 5]),
            'gameParams' => ['CLIENT_SETTINGS' => '{}']];
    }

    /** @return array{action: string, core: array<string, mixed>, game: array<string, mixed>} */
    private function step(string $action, int $depot, bool $finished, int $uncommitted, array $next, string $flow, int $fg = 0): array
    {
        return ['action' => $action, 'core' => ['depot' => ['balance' => $depot, 'limitBalance' => $depot], 'isGameFinished' => $finished],
            'game' => ['mainGameResult' => ['winnings' => [['winSum' => 5, 'winFactor' => 5]], 'freeGameRound' => $fg, 'freeGamesTotal' => 2,
                'childGameResult' => $fg ? ['winnings' => [['winSum' => 5]]] : null],
                'nextGameActions' => array_map(fn ($id) => ['id' => $id, 'minTotalWager' => 0, 'maxTotalWager' => 0], $next),
                'accounting' => ['debit' => 5, 'credit' => $uncommitted], 'uncommittedWinSum' => $uncommitted, 'lastWagerSum' => 5,
                'nextGameFlowName' => $flow, 'responseType' => 'ACTION']];
    }

    public function test_launcher_setup_is_ours(): void
    {
        [, , $s] = $this->merkurGame();
        $this->get("/merkur/{$s->token}/engine?req=gameproviderintegrationtype")->assertOk()->assertJson(['gameProviderIntegrationType' => 'edict']);
        $this->get("/merkur/{$s->token}/engine?req=whow-fm&_=123")->assertOk()->assertJsonPath('domainDTO.currencyDisplayInfo.currencyCode', 'EUR');
        $gw = $this->get("/merkur/{$s->token}/engine?req=whow-fm&type=gateway&/players/null/test_mk_html/templates/default/money/{5}?restart=false")
            ->assertOk()->json('gameLauncherData');
        $this->assertStringEndsWith('/test_mk_html/html/index-mobile.html', $gw['launchURL']);
        $this->assertStringContainsString('/games/TestMerkur~v', $gw['gameRootURL']);
        $this->assertSame('false', $gw['gameClientConfiguration'][0]['value']);   // no ladder gamble
        $this->postJson("/merkur/{$s->token}/engine?/services/gaming/5/v2/gateway/casinos/whow-fm/v2/templates/1/money/commands/start&portalLocale=en", [])
            ->assertOk()->assertJson(['de.edict.eoc.gaming.comm.JoinSessionResponseDTO' => ['depot' => ['balance' => 10000]]]);
        $this->get('/merkur/nope/engine?req=whow-fm')->assertStatus(401);
    }

    public function test_init_offers_our_coins_on_the_largest_line_set(): void
    {
        [, , $s] = $this->merkurGame();
        [$core, $g] = $this->command($s, ['gameActionId' => 'INIT']);

        $this->assertSame(10000, $core['depot']['balance']);
        $this->assertSame([5, 10, 25], $g['coins']);   // split coins: the total bets
        $this->assertCount(1, $g['wagerPositionSets']);
        $this->assertSame(1, $g['wagers'][0]['wager']);   // per line
        $this->assertSame(['y'], array_values($g['translations']));
    }

    public function test_coins_are_per_line_unless_split(): void
    {
        [, , $s] = $this->merkurGame(false);
        [, $g] = $this->command($s, ['gameActionId' => 'INIT']);

        $this->assertSame([1, 2, 5], $g['coins']);
    }

    public function test_an_older_client_with_line_choice_is_offered_the_full_set_twice(): void
    {
        [$game, , $s] = $this->merkurGame(false);
        $tpl = $game->template;
        $tpl->update(['bonus_config' => ['merkur' => array_merge($tpl->bonus_config['merkur'], ['repeat_line_set' => true])]]);
        [, $g] = $this->command($s, ['gameActionId' => 'INIT']);

        $this->assertCount(2, $g['wagerPositionSets']);   // "a set to choose from": the coin stays per line
        $this->assertSame($g['wagerPositionSets'][0], $g['wagerPositionSets'][1]);
        $this->assertSame([1, 2, 5], $g['coins']);
    }

    public function test_a_round_with_free_games_is_paid_at_the_play_and_served_scaled(): void
    {
        [, $player, $s] = $this->merkurGame();
        // recorded at 1/line (5 total) from depot 1000: wins 5, two free games (+5, +5), collected: 15
        $round = MerkurRound::create(['game_key' => 'test_mk_html', 'bet_cents' => 5, 'win_cents' => 15, 'feature' => true, 'start_credit' => 1000, 'steps' => [
            $this->step('PLAY', 995, false, 5, ['PLAY'], 'FREE_GAME'),
            $this->step('PLAY', 995, false, 10, ['PLAY'], 'FREE_GAME', 1),
            $this->step('PLAY', 995, false, 15, ['FINISH_GAME', 'RISK_BLACKRED_CHOICE', 'RISK_LADDER_CHOICE'], 'RISK_CHOICE', 2),
            $this->step('FINISH_GAME', 1010, true, 0, ['PLAY'], 'MAIN_GAME', 2),
        ]]);
        Cache::put('merkur:force:test_mk_html', $round->id, 60);
        $this->command($s, ['gameActionId' => 'INIT']);

        // at 5/line (× 5): 0.25 taken, the whole win (0.75) paid at once
        [$core, $g] = $this->command($s, $this->play(5));
        $this->assertEquals(100.5, (float) $player->wallet->fresh()->balance);
        $this->assertSame(9975, $core['depot']['balance']);   // the recording's view: stake out, win not yet in
        $this->assertFalse($core['isGameFinished']);
        $this->assertSame(25, $g['uncommittedWinSum']);
        $this->assertSame(25, $g['mainGameResult']['winnings'][0]['winSum']);
        $this->assertSame(5, $g['mainGameResult']['winnings'][0]['winFactor']);   // not money

        [, $g] = $this->command($s, ['gameActionId' => 'PLAY', 'wagers' => []]);   // a free game: nothing taken
        $this->assertSame(50, $g['uncommittedWinSum']);
        $this->assertSame(25, $g['mainGameResult']['childGameResult']['winnings'][0]['winSum']);
        [, $g] = $this->command($s, ['gameActionId' => 'PLAY', 'wagers' => []]);
        $this->assertSame(['FINISH_GAME', 'RISK_BLACKRED_CHOICE'], array_column($g['nextGameActions'], 'id'));   // never the ladder
        [$core, $g] = $this->command($s, ['gameActionId' => 'FINISH_GAME']);
        $this->assertTrue($core['isGameFinished']);
        $this->assertSame(10050, $core['depot']['balance']);
        $this->assertSame(['PLAY'], array_column($g['nextGameActions'], 'id'));
        $this->assertEquals(100.5, (float) $player->wallet->fresh()->balance);
    }

    public function test_card_gamble_and_divide_move_only_what_is_at_stake(): void
    {
        [, $player, $s] = $this->merkurGame();
        $round = MerkurRound::create(['game_key' => 'test_mk_html', 'bet_cents' => 5, 'win_cents' => 20, 'start_credit' => 1000, 'steps' => [
            $this->step('PLAY', 995, false, 20, ['FINISH_GAME', 'RISK_BLACKRED_CHOICE'], 'RISK_CHOICE'),
            $this->step('FINISH_GAME', 1015, true, 0, ['PLAY'], 'MAIN_GAME'),
        ]]);
        Cache::put('merkur:force:test_mk_html', $round->id, 60);
        $this->command($s, ['gameActionId' => 'INIT']);
        $this->command($s, $this->play(1));   // 0.05 in, 0.20 won → 100.15
        $this->assertEquals(100.15, (float) $player->wallet->fresh()->balance);

        [$core, $g] = $this->command($s, ['gameActionId' => 'RISK_BLACKRED_CHOICE']);
        $this->assertSame(9995, $core['depot']['balance']);
        $this->assertSame(['FINISH_GAME', 'RISK_BLACKRED_RED', 'RISK_BLACKRED_BLACK', 'RISK_DIVIDE'], array_column($g['nextGameActions'], 'id'));
        $this->assertSame(20, $g['addOnGameResult']['nextWagerStep']);

        [$core, $g] = $this->command($s, ['gameActionId' => 'RISK_DIVIDE']);   // 0.10 banked, 0.10 at stake
        $this->assertSame(10, $g['uncommittedWinSum']);
        $this->assertSame(10005, $core['depot']['balance']);

        [$core, $g] = $this->command($s, ['gameActionId' => 'RISK_BLACKRED_RED']);
        $card = $g['addOnGameResult']['drawnCard'];
        if ($g['addOnGameResult']['won']) {
            $this->assertContains($card['suitId'], [0, 1]);   // red
            $this->assertSame(20, $g['uncommittedWinSum']);
            $this->assertEquals(100.25, (float) $player->wallet->fresh()->balance);
        } else {
            $this->assertContains($card['suitId'], [2, 3]);
            $this->assertSame(['FINISH_GAME'], array_column($g['nextGameActions'], 'id'));
            $this->assertEquals(100.05, (float) $player->wallet->fresh()->balance);
        }
        $this->assertSame((int) round((float) $player->wallet->fresh()->balance * 100) - $g['uncommittedWinSum'], $core['depot']['balance']);

        [$core] = $this->command($s, ['gameActionId' => 'FINISH_GAME']);
        $this->assertTrue($core['isGameFinished']);
        $this->assertSame((int) round((float) $player->wallet->fresh()->balance * 100), $core['depot']['balance']);
        $this->command($s, ['gameActionId' => 'RISK_BLACKRED_RED'], 400);   // round over
    }

    public function test_bad_bets_and_actions_are_refused(): void
    {
        [, $player, $s] = $this->merkurGame();
        $this->command($s, ['gameActionId' => 'INIT']);
        $this->command($s, $this->play(3), 400);                                 // 0.15 is no bet option
        $this->command($s, ['gameActionId' => 'PLAY', 'wagers' => [['wager' => 1, 'wagerPositionId' => 1]]], 400);   // one line of five
        $this->command($s, ['gameActionId' => 'RISK_LADDER_CHOICE'], 400);
        $player->wallet->update(['balance' => 0.1]);
        $this->command($s, $this->play(5), 400);                                 // 0.25 > balance
        $this->assertEquals(0.1, (float) $player->wallet->fresh()->balance);
    }

    public function test_recording_plays_every_line_at_the_smallest_total(): void
    {
        $init = ['coins' => [10, 20, 50], 'wagerPositionSets' => [['wagerPositionIds' => [1, 2, 3, 4, 5]], ['wagerPositionIds' => range(1, 10)]]];
        $this->assertSame(['positions' => range(1, 10), 'wager' => 1, 'coin' => 10, 'stake' => 10], MerkurLiveClient::recording($init, true));
        $this->assertSame(['positions' => range(1, 10), 'wager' => 10, 'coin' => 10, 'stake' => 100], MerkurLiveClient::recording($init, false));
    }

    /** @return array{action: string, core: array<string, mixed>, game: array<string, mixed>} */
    private function duelStep(string $action, int $round, ?int $player, string $flow, int $uncommitted, array $wins = []): array
    {
        $addon = ['creatorName' => 'DUEL', 'totalRounds' => 3, 'currentRound' => $round, 'activeLines' => 5, 'multiplier' => 1,
            'playerConsumedIndex' => $player, 'opponentConsumedIndex' => $player === null ? null : $player + 1,
            'drawFactors' => [4, 30, 9, 20, 7, 5], 'consumedIndeces' => [], 'winnings' => $wins];

        return ['action' => $action, 'core' => ['depot' => ['balance' => 995, 'limitBalance' => 995], 'isGameFinished' => true],
            'game' => ['mainGameResult' => ['winnings' => [], 'freeGameRound' => 0, 'freeGamesTotal' => 0],
                'nextGameActions' => array_map(fn ($id) => ['id' => $id, 'minTotalWager' => 0, 'maxTotalWager' => 0],
                    $flow === 'ADD_ON_GAME' ? ['PLAY', 'FINISH_GAME'] : ['FINISH_GAME', 'RISK_BLACKRED_CHOICE']),
                'accounting' => ['debit' => 5, 'credit' => $uncommitted], 'uncommittedWinSum' => $uncommitted, 'lastWagerSum' => 5,
                'addOnGameInitResult' => $round === 0 ? $addon : null, 'addOnGameResult' => $round === 0 ? null : $addon,
                'nextGameFlowName' => $flow, 'responseType' => 'ACTION']];
    }

    public function test_baker_streets_duel_replays_next_offers_and_settles_a_taken_offer(): void
    {
        [, $player, $s] = $this->merkurGame();
        // recorded at 5 total from depot 1000: the spin opens the duel (no win of its own), every offer
        // passed; the last shot's factor (index 3: 20) paid 20 × 5 = 100, then collected
        $steps = [
            $this->duelStep('PLAY', 0, null, 'ADD_ON_GAME', 0),
            $this->duelStep('PLAY', 1, 1, 'ADD_ON_GAME', 0),                     // offer: factor 30
            $this->duelStep('PLAY', 2, 2, 'ADD_ON_GAME', 0),                     // offer: factor 9
            $this->duelStep('PLAY', 3, 3, 'RISK_CHOICE', 100, [['wagerPositionId' => 101, 'wagerId' => 2, 'winFactor' => 200, 'winSum' => 100]]),
            $this->step('FINISH_GAME', 1095, true, 0, ['PLAY'], 'MAIN_GAME'),
        ];
        $round = MerkurRound::create(['game_key' => 'test_mk_html', 'bet_cents' => 5, 'win_cents' => 100, 'feature' => true, 'start_credit' => 1000, 'steps' => $steps]);
        $this->command($s, ['gameActionId' => 'INIT']);

        // the player passes every offer: the recording, as it was
        Cache::put('merkur:force:test_mk_html', $round->id, 60);
        [$core, $g] = $this->command($s, $this->play(1));
        $this->assertTrue($core['isGameFinished']);           // as recorded: the duel runs on a finished game
        $this->assertSame('ADD_ON_GAME', $g['nextGameFlowName']);
        $this->assertEquals(100.95, (float) $player->wallet->fresh()->balance);   // 100 − 0.05 + 1.00
        foreach ([1, 2] as $k) {
            [, $g] = $this->command($s, ['gameActionId' => 'PLAY', 'gameParams' => ['userChoice' => 1], 'wagers' => null]);
            $this->assertSame($k, $g['addOnGameResult']['currentRound']);
        }
        [, $g] = $this->command($s, ['gameActionId' => 'PLAY', 'gameParams' => ['userChoice' => 1], 'wagers' => null]);
        $this->assertSame(100, $g['uncommittedWinSum']);
        [$core] = $this->command($s, ['gameActionId' => 'FINISH_GAME', 'gameParams' => [], 'wagers' => null]);
        $this->assertSame(10095, $core['depot']['balance']);

        // at 5 a line (× 5): the player takes the first offer — factor 30 × 0.25 = 7.50, not the recorded 5.00
        Cache::put('merkur:force:test_mk_html', $round->id, 60);
        $this->command($s, $this->play(5));                    // 100.95 − 0.25 + 5.00
        $this->assertEquals(105.7, (float) $player->wallet->fresh()->balance);
        $this->command($s, ['gameActionId' => 'PLAY', 'gameParams' => ['userChoice' => 1], 'wagers' => null]);
        [$core, $g] = $this->command($s, ['gameActionId' => 'FINISH_GAME', 'gameParams' => ['userChoice' => 0], 'wagers' => null]);
        $this->assertSame(750, $g['uncommittedWinSum']);
        $this->assertSame(750, $g['addOnGameResult']['winnings'][0]['winSum']);
        $this->assertSame(300, $g['addOnGameResult']['winnings'][0]['winFactor']);   // 10 per factor point, as recorded
        $this->assertSame('RISK_CHOICE', $g['nextGameFlowName']);
        $this->assertEquals(108.2, (float) $player->wallet->fresh()->balance);   // + 2.50 settled
        [$core, $g] = $this->command($s, ['gameActionId' => 'FINISH_GAME', 'gameParams' => [], 'wagers' => null]);
        $this->assertSame(10820, $core['depot']['balance']);
        $this->assertSame(['PLAY'], array_column($g['nextGameActions'], 'id'));
    }
}
