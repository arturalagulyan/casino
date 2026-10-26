<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameSession;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\SocketServer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 100-line Amatic games put their real line count on the wire, and the
 * Blazing Coins client ("coins" layout) reads 2-hex reel symbols plus a
 * booster / coin-value tail — frames decoded with that client's grammar.
 */
class AmaticCoinsLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Game, 1: User} */
    private function coinsGame(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create([
            'name' => 'Coins', 'slug' => 'coins', 'frontend' => 'default', 'currency' => 'EUR',
            'rtp_percent' => 94, 'max_win_multiplier' => 500, 'player_limit' => 100000,
        ]);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR', 'slots' => 100000]);

        $strip = [1, 1, 1, 1, 6, 6, 6, 3, 3, 3, 5, 5, 5, 0, 0, 0, 0, 5, 5, 5, 4, 4, 4, 2, 2, 2, 3, 1, 1];   // no scatter, so the win list is all line credits
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = array_map(fn ($r) => ($i + $r) % 4, range(0, 4));
        }
        $tpl = GameTemplate::create([
            'code' => 'TestCoinsSlot', 'title' => 'Test Coins Slot',
            'engine' => 'internal', 'client_protocol' => ClientProtocol::Amatic,
            'reel_count' => 5, 'row_count' => 4, 'symbol_count' => 8,
            'symbols' => range(0, 7), 'wild_symbol' => 0, 'scatter_symbol' => 7,
            'min_match' => 3, 'has_free_spins' => false, 'has_gamble' => true,
            'volatility' => 'medium',
            'paytable' => array_fill(0, 8, [0, 0, 0, 5, 20, 100]),
            'reel_strips' => array_combine(
                array_map(fn ($i) => "reelStrip{$i}", range(1, 5)),
                array_fill(0, 5, $strip),
            ),
            'paylines' => $lines,
            'bonus_config' => ['client_layout' => 'coins'],
        ]);

        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true, 'bet_options' => [0.1, 0.2],
            'rtp_percent' => 94, 'max_win_multiplier' => 500,
        ]);

        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $player->wallet->update(['balance' => 10000]);

        return [$game, $player];
    }

    /** Cursor reader mirroring the client's Y3I / Y3a / Y3r primitives. */
    private function reader(string $s): object
    {
        return new class($s)
        {
            public int $i = 0;

            public function __construct(public string $s) {}

            public function one(): int
            {
                return hexdec($this->s[$this->i++]);
            }

            public function two(): int
            {
                $v = hexdec(substr($this->s, $this->i, 2));
                $this->i += 2;

                return $v;
            }

            public function len(): int
            {
                $n = $this->one();
                $v = substr($this->s, $this->i, $n);
                $this->i += $n;

                return $n ? hexdec($v) : 0;
            }

            /** @return list<int> */
            public function wide(): array
            {
                $out = [];
                for ($n = $this->len(), $k = 0; $k < $n; $k++) {
                    $out[] = $this->two();
                }

                return $out;
            }
        };
    }

    public function test_init_and_spin_frames_follow_the_coins_client_grammar(): void
    {
        [$game, $player] = $this->coinsGame();
        $session = GameSession::create([
            'user_id' => $player->id, 'game_id' => $game->id, 'token' => 'coins-'.uniqid(), 'is_active' => true,
        ]);
        $server = app(SocketServer::class);

        // ---- init (client YRZ.Y3D type 0 + YCo.YAP)
        $init = $server->handle(11, "A/u25,,{$session->token},TestCoinsSlot,2_0_0,,,2|15|0|en-US |1920|1080|1")[0];
        $p = $this->reader($init);
        $this->assertSame(0, $p->one());
        $this->assertSame(5, $p->one());
        $first = $p->wide();
        $this->assertSame([1, 1, 1, 1, 6], array_slice($first, 0, 5));   // 2 hex digits per symbol
        for ($r = 1; $r < 5; $r++) {
            $p->wide();
        }
        for ($r = $p->one(); $r > 0; $r--) {
            $p->wide();
        }
        $p->two();
        $p->one();
        for ($r = 0; $r < 5; $r++) {
            $p->len();
        }
        $p->len();
        $p->len();
        $p->len();
        $p->two();
        $p->len();
        $this->assertSame(2000, $p->len());           // max bet = 0.2 × 100 lines, in cents
        $this->assertSame(100, $p->two());            // lines
        for ($k = 0; $k < 7; $k++) {
            $p->len();
        }
        $p->two();
        $p->two();
        $p->two();
        $p->len();
        $p->two();
        for ($r = 0; $r < 5; $r++) {
            $p->len();
        }
        for ($k = 0; $k < 8; $k++) {
            $p->two();
        }
        $this->assertSame(2, $p->two());              // bets
        $p->len();
        $p->len();
        $this->assertSame(101, $p->two());            // 100 lines + scatter
        for ($k = 0; $k < 101; $k++) {
            $p->len();
        }
        $this->assertSame(0, $p->len());              // booster cap: none
        $this->assertSame(100, $p->len());            // booster factor ×1.00
        $this->assertSame(15, $p->two());             // booster off
        for ($r = 0; $r < 5; $r++) {
            $this->assertCount(10, $p->wide());
        }
        $this->assertSame('#00101010|0', substr($init, $p->i));

        // ---- spin (type 1): stake is bet × 100 lines, tail lands exactly on '#101010'
        $frame = $server->handle(11, 'A/u251,100,0,1,-1;-1,-1,0,0,-1,15,1,-1,0,0,-1')[0];
        $this->assertSame('10.0000', $player->rounds()->latest('id')->first()->bet);

        $p = $this->reader($frame);
        $this->assertSame(1, $p->one());
        $p->two();
        $p->one();
        $p->len();
        $p->len();
        $win = $p->len();
        for ($r = 0; $r < 5; $r++) {
            $p->len();
        }
        $p->two();
        $this->assertSame(100, $p->two());
        for ($k = 0; $k < 6; $k++) {
            $p->len();
        }
        for ($r = 0; $r < 5; $r++) {
            $p->len();
        }
        $this->assertSame(101, $p->two());
        $credits = 0;
        for ($k = 0; $k < 101; $k++) {
            $credits += $p->len();
        }
        $this->assertSame($win, $credits * 10);       // per-line credits × 0.1 bet, in cents
        for ($k = 0; $k < 8; $k++) {
            $p->two();
        }
        $this->assertSame(15, $p->two());
        for ($r = 0; $r < 5; $r++) {
            $this->assertCount(10, $p->wide());
        }
        $this->assertSame('#101010', substr($frame, $p->i));
    }
}
