<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\SocketServer;
use App\Services\Legacy\EgtGameParser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Amatic "ways pays" games (All Ways / Enchanted Cleopatra family): no
 * paylines — a symbol pays on adjacent reels from the left × the matching
 * cell count of each reel; symbol 10 doubles the total.
 */
class AmaticWaysTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Game, 1: User} */
    private function waysGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Ways', 'slug' => 'ways', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 94, 'max_win_multiplier' => 500, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $strip = [3, 6, 5, 8, 7, 9, 3, 6, 1, 4, 5, 10, 6, 7, 8, 4, 3, 6, 7, 10, 8, 4, 2, 0];
        $tpl = GameTemplate::create([
            'code' => 'TestWaysSlot', 'title' => 'Test Ways Slot',
            'engine' => 'internal', 'client_protocol' => ClientProtocol::Amatic,
            'reel_count' => 5, 'row_count' => 3, 'symbol_count' => 11,
            'symbols' => range(0, 10), 'wild_symbol' => null, 'scatter_symbol' => 9,
            'min_match' => 3, 'has_free_spins' => true, 'has_gamble' => true,
            'free_spins_table' => [0, 0, 0, 7, 10, 15], 'free_spins_multiplier' => 1,
            'volatility' => 'medium',
            'paytable' => [
                0 => [0, 0, 0, 10, 50, 500], 1 => [0, 0, 0, 7, 25, 100], 2 => [0, 0, 0, 5, 10, 20],
                3 => [0, 0, 0, 3, 5, 10], 4 => [0, 0, 0, 3, 5, 10], 5 => [0, 0, 0, 2, 4, 8],
                6 => [0, 0, 0, 2, 4, 8], 7 => [0, 0, 0, 2, 4, 8], 8 => [0, 0, 0, 1, 2, 5],
                9 => [0, 0, 0, 5, 25, 50], 10 => [0, 0, 0, 0, 0, 0],
            ],
            'reel_strips' => array_combine(
                array_map(fn ($i) => "reelStrip{$i}", range(1, 5)),
                array_fill(0, 5, $strip),
            ),
            'paylines' => null,
            'bonus_config' => [
                'triggers' => ['9' => ['flow' => 'free_spins', 'min' => 3]],
                'ways' => ['symbols' => range(0, 8), 'doubler' => 10, 'free_any_reel' => true],
            ],
        ]);

        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [1, 2, 5],
            'rtp_percent' => 94, 'max_win_multiplier' => 500,
        ]);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 10000]);

        return [$game, $player];
    }

    private function config(Game $game): GameConfig
    {
        return new GameConfig($game->template, $game);
    }

    /** @param list<list<int>> $board [reel][row] */
    private function evaluate(Game $game, array $board, bool $free = false): array
    {
        return app(SlotEngine::class)->evaluate($board, $this->config($game), 1.0, 1, free: $free);
    }

    public function test_adjacent_reels_pay_times_the_matching_cell_counts(): void
    {
        [$game] = $this->waysGame();

        // symbol 3: 2 cells on reel 1, 1 on reel 2, 2 on reel 3 → 3-run × (2·1·2)
        $eval = $this->evaluate($game, [
            [3, 3, 1], [5, 3, 6], [3, 7, 3], [1, 2, 4], [6, 5, 7],
        ]);

        $this->assertEqualsWithDelta(3 * 4, $eval['win'], 0.0001);
        $this->assertCount(1, $eval['lines']);
        $this->assertSame(3, $eval['lines'][0]['line']);   // win-list slot = symbol's index
        $this->assertSame(3, $eval['lines'][0]['count']);
    }

    public function test_a_gap_breaks_the_run_in_the_base_game_but_not_in_free_spins(): void
    {
        [$game] = $this->waysGame();
        // symbol 0 on reels 1, 2, 4, 5 — reel 3 missing
        $board = [[0, 1, 2], [4, 0, 5], [6, 7, 8], [0, 1, 2], [3, 4, 0]];

        $this->assertSame(0.0, $this->evaluate($game, $board)['win']);
        // free spins count the 4 reels holding it anywhere → pays[4] = 50
        $this->assertEqualsWithDelta(50, $this->evaluate($game, $board, free: true)['win'], 0.0001);
    }

    public function test_each_doubler_doubles_the_whole_win_including_scatters(): void
    {
        [$game] = $this->waysGame();

        // 3× symbol 8 (pays 1) + 3 scatters (pays 5) = 6, two doublers → ×4
        $eval = $this->evaluate($game, [
            [8, 9, 1], [8, 10, 2], [8, 9, 4], [10, 5, 6], [9, 7, 1],
        ]);

        $this->assertEqualsWithDelta(24, $eval['win'], 0.0001);
        $this->assertSame(3, $eval['scatters'][9]);
    }

    public function test_settings_and_spin_frames_list_one_win_per_symbol(): void
    {
        [$game, $player] = $this->waysGame();
        $session = GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id, 'token' => 'ways-'.uniqid(), 'is_active' => true,
        ]);
        $server = app(SocketServer::class);

        $init = $server->handle(9, "A/u25,,{$session->token},TestWaysSlot,2_0_0,,,2|15|4|en-US |1280|720|1")[0];
        // empty 10-entry win list (9 symbols + scatter), as Amatic's own server sends it
        $this->assertStringEndsWith('0a'.str_repeat('10', 10).'#00101010|0', $init);

        // the client sends its symbol count (9) as "lines"; the stake is one bet
        $frame = $server->handle(9, 'A/u251,9,1')[0];

        $round = $player->rounds()->latest('id')->first();
        $this->assertSame('2.0000', $round->bet);

        // … <bet:01><lines:09> 6 flags 5×kLW, then a 0a-long win list, 8 cards, #101010
        $this->assertMatchesRegularExpression(
            '/0109(?:[0-9a-f]+)1010(?:[1-9][0-9a-f]+){5}0a(?:[1-9][0-9a-f]+){10}(?:00){8}#101010$/',
            $frame,
        );
    }

    public function test_ways_free_spins_are_free_and_keep_the_symbol_count_as_lines(): void
    {
        [$game, $player] = $this->waysGame();
        $session = GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id, 'token' => 'ways-'.uniqid(), 'is_active' => true,
            'state' => ['features' => [
                'last_bet' => 2.0, 'last_lines' => 1, 'last_bet_index' => 1,
                'frozen_balance' => 9998.0, 'bonus_win' => 0.0, 'total_win' => 0.0,
                'free_total' => 7, 'free_left' => 7,
            ]],
        ]);
        $server = app(SocketServer::class);
        $server->handle(10, "A/u25,,{$session->token},TestWaysSlot,2_0_0,,,2|15|4|en-US |1280|720|1");

        $frame = $server->handle(10, 'A/u256')[0];

        $this->assertMatchesRegularExpression('/^1(06|0a|0c)0/', $frame);
        // <bet:01><lines:09><freeInfo: total, left><freeWinState><bonusMpl:11>
        $this->assertMatchesRegularExpression('/0109(?:[1-9][0-9a-f]+){2}1[09]11/', $frame);
        $this->assertMatchesRegularExpression('/0a(?:[1-9][0-9a-f]+){10}(?:00){8}#101010$/', $frame);
        $features = $session->fresh()->state['features'];
        $retriggered = $features['free_total'] - 7;
        $this->assertSame(6 + $retriggered, $features['free_left']);
        $this->assertSame('0.0000', $player->rounds()->latest('id')->first()->bet);
    }

    public function test_parser_detects_a_legacy_ways_package(): void
    {
        $dir = storage_path('framework/testing/WaysPkgAM');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SlotSettings.php', <<<'PHP'
            <?php
            $this->Paytable['SYM_0'] = [0, 0, 0, 10, 50, 500];
            $this->Paytable['SYM_1'] = [0, 0, 0, 7, 25, 100];
            $this->Paytable['SYM_9'] = [0, 0, 0, 5, 25, 50];
            $this->Paytable['SYM_10'] = [0, 0, 0, 0, 0, 0];
            $this->slotBonus = true;
            $this->slotFreeCount = [0, 0, 0, 7, 10, 15];
            $this->SymbolGame = ['0', '1'];
            PHP);
        File::put($dir.'/Server.php', <<<'PHP'
            <?php
            $wild = ['none'];
            $scatter = '9';
            for( $s = 0; $s <= 2; $s++ ) { $cntsym[$r - 1]++; }
            if( $postData['slotEvent'] == 'freespin' )
            {
                $sc = 0;
            }
            $sMpl = $sMpl * $cntsym[$r - 1];
            if( $reels['reel' . $r][$p] == '10' )
            {
                $symDouble = $symDouble * 2;
            }
            PHP);
        File::put($dir.'/reels.txt', "reelStrip1=0,1,9,10\nreelStrip2=0,1,9,10\nreelStrip3=0,1,9,10\nreelStrip4=0,1,9,10\nreelStrip5=0,1,9,10\n");

        $parser = EgtGameParser::fromDir($dir, 'WaysPkgAM');
        $attrs = $parser->templateAttributes();
        File::deleteDirectory($dir);

        $this->assertTrue($parser->isWaysSlot());
        $this->assertFalse($parser->isLineSlot());
        $this->assertNull($attrs['paylines']);
        $this->assertNull($attrs['wild_symbol']);
        $this->assertSame(3, $attrs['row_count']);
        $this->assertSame([0, 0, 0, 7, 10, 15], $attrs['free_spins_table']);
        $this->assertSame(['symbols' => [0, 1], 'doubler' => 10, 'free_any_reel' => true], $attrs['bonus_config']['ways']);
    }
}
