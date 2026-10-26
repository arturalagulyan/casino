<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\Amatic\AmaticWildcatInit;
use App\Services\GamePlay\Protocol\AmaticHoldWinHandler;
use App\Services\GamePlay\SocketServer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Amatic WildCat "Hold & Win" games (JadeEmperor): lines on Amatic's own
 * strips, coins + collectors, a respin bonus banked by the collectors.
 */
class AmaticHoldWinTest extends TestCase
{
    use RefreshDatabase;

    /** Amatic's own JadeEmperor init frame (free play). */
    private const string INIT = '-10528306060105050305050306080802050504070701050504070704040808070303060b0105050606020605050806060305050c060603060d05050e070303060f07070406060308080707060603100505110806060312060605050506060206000505000102030300050500050501020303030505020505030306060613080808140707071529d070802040103080508060600070308070205030807080804050108080406060308080406060607030808040701080905070908080709050808040806060907020703080907030809070509080208080509080608030805070808020401080702000307050504080107030805000808010803070508040103030308050106030709050505040806000308050504080604060105030805070707080808092890808070b06020505050800070606010808040305070707080606020807050806070607070c08060708080705080d05060606080208070705050e0800070706070508050606010f0802071006060207060203040402060600080205050206070002070702070705020708020607020711050502080602030704020612070002071308070814060715081010003005101010101010530d4010001a33e805101010101010100505051100051010101010000000000000000015121416181a21421e22823223c25026427828c2a02b42c831903258332033e8061010101010101210122642c81921a0a0b0a0a0a0c0a0a0a0d0a0e0a0f0a0a0a0a100a110a120a0a1321a0a110a0a0a0e0a0a0a0d0a0a0b0a100a0a0a0f0a120a0c0a0a1321a0a0d0a0a100a0a0a0a0c0a0e0a0b0a0a0a0a130a110a120a0a0f21a0a0a090a0a0a0a0a090a0a0a0a090a0a0a0a0a0a0a0a0a090a0a21a0a0a0a090a0a0a090a0a0a0a0a090a0a0a0a0a0a0a0a0a090a0a21a0a0a0a0a0a090a0a0a0a0a0a090a0a0a0a0a0a0a0a0a090a0a0921a0a0c0a0a0a0b0a0a0a0d0a110a0f0a0a0a0a130a120a100a0a0e21a0a0d0a0a0a120a0a0a100a0e0a110a0b0a0a0c0a0a0a0f0a130a21a0a120a0a0a0c0a0a0a100a0b0a0f0a0a0d0a110a0a0a0e0a0a13f1313150606130907081308080810131b02010f0101010f010205011b0102010501010a010201021b0102010201050101010201|95.92%;;95.99%,95.95%|90|6081496#00101010|0#10';

    /** Amatic's own DoubleJokerHoldAndWin init frame (free play). */
    private const string DJ_INIT = '-10528316161115151315151316181812151514171711151514171714141818171313161c1115151616121615151816161315151d161613161e15151f1713131621017171416161318181717161613211151521218161613213161615151516161216101515101112131310151510151511121313131515121515131316161621418181821517171721629d171812141113181518161610171318171215131817181814151118181416161318181416161617131818141711181915171918181719151818141816161a17121713181a1713181a17151918121818151a1816181318151718181214111817121013171515141811171318151018181118131715181411131313181511161317191515151418161113181515141816141611151318151717171818181a2891818171c16121515151810171616111818141315171717181616121817151816171617171d18161718181715181e15161616181218171715151f1810171716171518151616112101812172111616121716121314141218161018121515121617101217171217171512171812161712172121515121816121317141416213171112172141817182151617216181010003005101010101010530d4010001a33e805101010101010100505051100051010101010000000000000000015121416181a21421e22823223c25026427828c2a02b42c831903258332033e80610101010101015101324b2642961139c421421a1b1c1b1b1b1d1b1b1b1e1b1f1b2101b1b1b1b2111b2121b2131b1b21421a1b2121b1b1b1f1b1b1b1e1b1b1c1b2111b1b1b2101b2131b1d1b1b21421a1b1e1b1b2111b1b1b1b1d1b1f1b1c1b1b1b1b2141b2121b2131b1b21021a1b1e1b1b2141b1b1b1b1d1b1f1b2111b1b1b1b1c1b2121b2131b1b21021a1b1c1b1b1b2101b1b1b1e1b1d1b1f1b1b1b1b2111b2141b2131b1b21221a1b2121b1b1b1f1b1b1b1e1b1b2141b2111b1b1b2101b1c1b1d1b1b21321a1b2131b1b2141b1b1b1b2111b1f1b1d1b1b1b1b1c1b1e1b2121b1b21021a1b2121b1b1b2101b1b1b1e1b2111b1c1b1b1b1b1d1b2141b2131b1b1f21a1b1b191b1b1b1b1b1a1b1b1b1b191b1b1b1b1b1b1b1b1b1a1b1b21a1b1b1b1a1b1b1b191b1b1b1b1b1a1b1b1b1b1b1b1b1b1b191b1b21a1b1b1b1b1b1a1b1b1b1b1b1b191b1b1b1b1b1b1b1b1b1a1b1b19131b1b1b21a1b1e1b1b1b2131b1b1b2111b1f1b2121b1c1b1b1d1b1b1b2101b2141b21a1b2131b1b1b1d1b1b1b2111b1c1b2101b1b1e1b2121b1b1b1f1b1b21421a1b1d1b1b1b1e1b1b1b1c1b2141b2101b1b1b1b2121b2131b2111b1b1f21a1b1e1b1b1b2131b1b1b2111b1d1b2121b1f1b1b1c1b1b1b2101b2141b21a1b2131b1b1b2121b1b1b2111b1c1b2101b1b1f1b1d1b1b1b1e1b1b21421a1b2121b1b1b2131b1b1b2111b1d1b1c1b1f1b1b1e1b1b1b2101b2141b21a1b2131b1b1b1c1b1b1b2121b2111b2101b1b1f1b2141b1b1b1e1b1b1d21a1b1d1b1b1b1c1b1b1b1e1b2121b2101b1b1b1b2141b2131b2111b1b1ff0013132161616131a17181318181810131b151211121f1a15121a151f1b151f1a111215111512121a1b151a1f1115121a1215151113131010101310101013101010|96.23%;96.23%;96.23%,96.23%,96.17%|90|5806625#00101010|0#10';

    /** Amatic's own ElToroFlamenco init frame (free play). */
    private const string ET_INIT = '-10524aGGGBGMIHHHDFCCCIECBBBNFIIIOCFGGGEAHHHEDGPQIIIHEDHEEEBIFFFGRSTFEIAAAGCDDDIH25dDIHGGGBGJIHHHDFCCCIECBBBKFIIIKCFGGGEAHHHEDIJKIIIHEDHEEEBJFFFGCIJFEIAAAGCDDDIHFGHHHFDCIDKHIIIF24aGGGBGMIHHHDFCCCIECBBBNFIIIOCFGGGEAHHHEDGPQIIIHEDHEEEBIFFFGRSTFEIAAAGCDDDIH1010003005101010101010530d4010001a33e805101010101010100505051100051010101010000000000000000015121416181a21421e22823223c25026427828c2a02b42c831903258332033e80610101010101018101324b2962fa21421aLMLLLNLLLOLPLQLLLLRLSLTLLU21aLSLLLPLLLOLLMLRLLLQLTLNLLU21aLOLLRLLLLNLPLMLLLLULSLTLLQ21aLOLLULLLLNLPLRLLLLMLSLTLLQ21aLMLLLQLLLOLNLPLLLLRLULTLLS21aLSLLLPLLLOLLULRLLLQLMLNLLT21aLTLLULLLLRLPLNLLLLMLOLSLLQ21aLSLLLQLLLOLRLMLLLLNLULTLLP21aLLJLLLLLKLLLLJLLLLLLLLLKLL21aLLLKLLLJLLLLLKLLLLLLLLLJLL21aLLLLLKLLLLLLJLLLLLLLLLKLLJ13LLL21aLOLLLTLLLRLPLSLMLLNLLLQLUL21aLTLLLNLLLRLMLQLLOLSLLLPLLU21aLNLLLOLLLMLULQLLLLSLTLRLLP21aLOLLLTLLLRLNLSLPLLMLLLQLUL21aLTLLLSLLLRLMLQLLPLNLLLOLLU21aLSLLLTLLLRLNLMLPLLOLLLQLUL21aLTLLLMLLLSLRLQLLPLULLLOLLN21aLNLLLMLLLOLSLQLLLLULTLRLLPf01010101313HGG13FDI13HGG1310101013101010131b1112111a121212111112121b11111512121215111211121b121212151212121111151213131010101310101013101010|96.00%;;96.00%,96.00%,96.00%|250|78027604#00101010|0#10';

    public function test_the_init_carries_the_real_strips_ladder_and_buy_prices(): void
    {
        $init = new AmaticWildcatInit;
        $strips = $init->strips(self::INIT);
        $this->assertSame([131, 157, 137], array_map('count', $strips));
        $this->assertContains(9, $strips[1]);              // the collector lands on reel 2 only
        $this->assertNotContains(9, $strips[0]);

        $tail = $init->tail(self::INIT, 2);
        $this->assertSame(2, $tail['ladder'][0]);
        $this->assertSame(5, $tail['lines']);
        $this->assertSame([100, 200], $tail['buy']);
        $this->assertStringContainsString('51e240', $init->withBalance(self::INIT, 123456, 2));
    }

    public function test_lines_pay_with_wilds_and_the_collectors_hold_the_bonus_win(): void
    {
        $hw = $this->holdWin();
        $handler = app(AmaticHoldWinHandler::class);
        for ($i = 0; $i < 200; $i++) {
            $round = $handler->round($hw, true);
            // 3 of a kind on a line, wild 0 substituting (3 wilds pay its own 250)
            foreach ($hw['lines'] as $k => $line) {
                $syms = array_map(fn ($r) => $round['grid'][$r][$line[$r]], [0, 1, 2]);
                $real = array_values(array_filter($syms, fn ($s) => $s !== 0));
                $expected = $real === [] ? 250 : (count(array_unique($real)) === 1 ? ($hw['pays'][$real[0]] ?? 0) : 0);
                $this->assertEqualsWithDelta($expected, $round['line_pays'][$k], 0.001);
            }
            if ($round['bonus'] === null) {
                continue;
            }
            $steps = $round['bonus']['steps'];
            $last = end($steps);
            $this->assertSame(0, $last['left']);
            $this->assertEqualsWithDelta(array_sum($last['totals']), $round['bonus_win'], 0.001);
        }
    }

    public function test_a_bought_bonus_streams_its_respins_and_settles_once(): void
    {
        [$game, $player] = $this->game();
        $server = app(SocketServer::class);
        $token = GameSession::create(['user_id' => $player->id, 'game_id' => $game->id, 'token' => 'hw-'.uniqid(), 'is_active' => true])->token;
        $server->handle(1, "A/u25,,{$token},JadeEmperor,2_0_0,,,2|15|0|en-US |1280|720|1");

        $frame = $server->handle(1, 'A/u2566,5,0,1,1')[0];      // 200 × 0.10, a cash pot guaranteed
        $this->assertSame(64, $this->state($frame));
        $after = (float) $player->wallet->fresh()->balance;
        $won = $after - (1000 - 20);
        $this->assertGreaterThanOrEqual(25 * 0.1, $won);       // at least the smallest cash pot
        // shown before the bonus pays (a line win on the triggering spin is already in)
        $this->assertGreaterThanOrEqual((1000 - 20) * 100, $this->balance($frame));
        $this->assertLessThan($after * 100 + 0.5, $this->balance($frame));

        for ($k = 0; $k < 300 && $this->state($frame) !== 48; $k++) {
            $frame = $server->handle(1, 'A/u2546,5,0,1')[0];
            $this->assertContains($this->state($frame), [46, 48]);
        }
        $this->assertSame(48, $this->state($frame));
        $this->assertEqualsWithDelta($after * 100, $this->balance($frame), 0.5);

        // back to base spins
        $this->assertContains($this->state($server->handle(1, 'A/u251,5,0,1')[0]), [9, 45]);
    }

    public function test_double_joker_multiplier_jokers_and_the_5x4_bonus_board(): void
    {
        $init = new AmaticWildcatInit;
        $hw = $this->holdWin() + [
            'layout' => 'v5', 'multi_collector' => 10, 'multi_share' => 0.25, 'single_collect' => true,
            'bonus_cols' => 5, 'bonus_rows' => 4, 'bonus_coin_cols' => [0, 1, 3, 4], 'bonus_collector_col' => 2,
            'multiplier_steps' => [2], 'multiplier_max' => 6, 'multiplier_cells' => 4,
        ];
        $hw['strips'] = $init->strips(self::DJ_INIT, 0);
        $hw = array_replace($hw, ['empty' => 11, 'coin_first' => 12, 'coin_chance' => 0.033, 'collector_chance' => 0.02,
            'bonus_values' => [1 => 180, 2 => 90, 5 => 290, 10 => 260, 15 => 160, 124 => 20, 125 => 10, 126 => 3, 127 => 0.4]]);
        $this->assertSame([131, 157, 137], array_map('count', $hw['strips']));
        $this->assertSame([75, 100, 150], $init->tail(self::DJ_INIT, 0)['buy']);

        $handler = app(AmaticHoldWinHandler::class);
        $seenMultiplier = false;
        for ($i = 0; $i < 300; $i++) {
            $round = $handler->round($hw, true, ['multiplier' => true, 'extra_multi' => 2]);
            $this->assertNotNull($round['bonus']);
            $this->assertContains(10, $round['bonus']['start']['collectors']);   // bought: starts with a multiplier joker
            foreach ($round['bonus']['steps'] as $step) {
                foreach ($step['mults'] as $key => $m) {
                    $this->assertLessThanOrEqual(6, $m);
                    [$c] = explode('.', (string) $key);
                    $this->assertContains((int) $c, [0, 1, 3, 4]);           // never on the joker column
                    $seenMultiplier = true;
                }
            }
            $last = end($round['bonus']['steps']);
            $this->assertEqualsWithDelta(array_sum($last['totals']), $round['bonus_win'], 0.001);
        }
        $this->assertTrue($seenMultiplier);

        // a lone coin with a joker pays its value at once
        for ($i = 0, $collects = 0; $i < 3000 && $collects < 5; $i++) {
            $round = $handler->round($hw);
            if ($round['collect_win'] > 0) {
                $collects++;
                $coin = array_sum(array_map('array_sum', $round['coins']));
                $factor = array_sum(array_map(fn ($s) => $s === 10 ? 2 : 1, $round['collectors']));
                $this->assertEqualsWithDelta($coin * $factor, $round['collect_win'], 0.001);
            }
        }
        $this->assertGreaterThan(0, $collects);
    }

    public function test_el_toro_letter_strips_respins_and_two_collector_buy(): void
    {
        $init = new AmaticWildcatInit;
        $strips = $init->strips(self::ET_INIT, 1);
        $this->assertSame([74, 93, 74], array_map('count', $strips));
        $this->assertSame([75, 150, 250], $init->tail(self::ET_INIT, 1)['buy']);

        $hw = array_replace($this->holdWin(), [
            'strips' => $strips, 'layout' => 'v8', 'empty' => 11, 'coin_first' => 12, 'multi_collector' => 10,
            'single_collect' => true, 'respin_chance' => 1.0, 'bonus_cols' => 5, 'bonus_rows' => 4,
            'bonus_coin_cols' => [0, 1, 3, 4], 'bonus_collector_col' => 2, 'multiplier_steps' => [2, 4, 6], 'multiplier_max' => 12,
            'value_table' => [1, 2, 5, 10, 15, 25, 50, 150, 1000], 'jackpots' => [25 => 25, 50 => 50, 150 => 150, 1000 => 1000],
            'bonus_values' => [1 => 180, 2 => 90, 5 => 290, 10 => 260, 15 => 160, 25 => 20, 50 => 10, 150 => 3, 1000 => 0.4],
            'coin_chance' => 0.03, 'collector_chance' => 0.02,
        ]);
        $handler = app(AmaticHoldWinHandler::class);
        $respins = 0;
        for ($i = 0; $i < 4000 && $respins < 5; $i++) {
            $round = $handler->round($hw);
            if ($round['respin'] === null) {
                continue;
            }
            $respins++;
            $held = array_values(array_filter([0, 1, 2], fn ($r) => (hexdec($round['respin']['mask']) >> $r) & 1));
            $this->assertCount(2, $held);
            foreach ($held as $r) {
                $this->assertSame($round['respin']['grid'][$r], $round['grid'][$r]);    // held reels stay
                $this->assertCount(1, array_unique($round['grid'][$r]));                // stacked
            }
            $this->assertNull($round['bonus']);
        }
        $this->assertGreaterThan(0, $respins);

        // 150× buy: a collector and a multiplier collector from the start
        $round = $handler->round($hw, true, ['start_collectors' => 2, 'multiplier' => true]);
        $this->assertCount(2, $round['bonus']['start']['collectors']);
        $this->assertContains(10, $round['bonus']['start']['collectors']);
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array<string, mixed> */
    private function holdWin(): array
    {
        return [
            'strips' => (new AmaticWildcatInit)->strips(self::INIT), 'rows' => 3,
            'lines' => [[1, 1, 1], [0, 0, 0], [2, 2, 2], [0, 1, 2], [2, 1, 0]],
            'pays' => [250, 150, 100, 80, 80, 20, 20, 20, 5], 'wild' => 0, 'collector' => 9, 'empty' => 10,
            'coin_first' => 11, 'coin_slots' => 11, 'collector_reel' => 1, 'coin_reels' => [0, 2],
            'value_table' => [1, 2, 5, 10, 15, 124, 125, 126, 127], 'jackpots' => [124 => 25, 125 => 50, 126 => 150, 127 => 1000],
            'base_values' => [1 => 570, 2 => 330, 5 => 80, 10 => 20, 15 => 5],
            'bonus_values' => [1 => 180, 2 => 90, 5 => 290, 10 => 260, 15 => 160, 124 => 20, 125 => 10, 126 => 3, 127 => 0.4],
            'coin_chance' => 0.095, 'collector_chance' => 0.03, 'respins' => 3, 'buy_options' => [[], ['pot' => true]],
        ];
    }

    /** @return array{0: Game, 1: User} */
    private function game(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = Shop::create([
            'name' => 'Hw', 'slug' => 'hw', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 94, 'max_win_multiplier' => 5000, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);
        $hw = $this->holdWin();
        $tpl = GameTemplate::create([
            'code' => 'JadeEmperor', 'title' => 'Jade Emperor', 'engine' => 'internal', 'client_protocol' => ClientProtocol::Amatic,
            'reel_count' => 3, 'row_count' => 3, 'symbol_count' => 22, 'symbols' => range(0, 21), 'wild_symbol' => 0,
            'min_match' => 3, 'has_free_spins' => false, 'has_gamble' => false, 'volatility' => 'medium',
            'paytable' => array_fill(0, 22, [0, 0, 0, 0]), 'paylines' => $hw['lines'],
            'reel_strips' => ['reelStrip1' => $hw['strips'][0], 'reelStrip2' => $hw['strips'][1], 'reelStrip3' => $hw['strips'][2]],
            'bonus_config' => ['table' => [
                'type' => 'wildcat', 'mechanic' => 'holdwin', 'init' => self::INIT, 'buy' => [100, 200], 'bet_factor' => 5, 'holdwin' => $hw,
            ]],
        ]);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1,
            'is_visible' => true, 'bet_options' => [0.1, 0.2], 'rtp_percent' => 94, 'max_win_multiplier' => 5000,
        ]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 1000]);

        return [$game, $player];
    }

    private function state(string $frame): int
    {
        return (int) hexdec(substr($frame, 3, 2));
    }

    private function balance(string $f): int
    {
        $i = 6;                                                 // '-11' state(2) message(1)
        $num = function () use ($f, &$i): int {
            $n = (int) hexdec($f[$i++]);
            $v = substr($f, $i, $n);
            $i += $n;

            return $n ? (int) hexdec($v) : 0;
        };
        $num();                                                 // round id

        return $num();
    }
}
