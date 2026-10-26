<?php

namespace Tests\Feature;

use App\Models\PlayNGoRound;
use App\Services\GamePlay\PlayNGo\MessagePack;
use App\Services\GamePlay\Protocol\PlayNGoProtocol;
use Tests\TestCase;

/**
 * Play'n GO rounds recorded at one coin value are served at another: coin
 * amounts stay, cash amounts and the balance are ours.
 */
class PlayNGoProtocolTest extends TestCase
{
    public function test_a_recorded_reply_is_rescaled_to_the_players_coin_value(): void
    {
        $recorded = "d=2 6\r\n2 0 3 0 0 4\r\n3 0 62 620 1 1\r\n1 10 1 10 3 1 2 2 12 4 0 0 5 3 5 7 2 3 0 2 0 0 0 2 3 3 5 0 4 1 11 1 3\r\n2 2 8 1 1 0 1 4 16 17 18 23 1\r\n2 5 1\r\n\r\n";

        $out = app(PlayNGoProtocol::class)->rescale($recorded, 50, 10, 123456);

        $this->assertContains('3 0 62 3100 1 1', $out);                 // 62 coins at 50 cents
        $this->assertStringStartsWith('1 10 1 50 3 1 2', $out[3]);      // board line names our coin value
        $this->assertContains('2 2 8 1 1 0 1 4 16 17 18 23 1', $out);   // coin values in awards untouched
        $this->assertSame('2 6', $out[0]);
    }

    public function test_the_collect_reply_pays_in_our_cash_and_shows_our_balance(): void
    {
        $out = app(PlayNGoProtocol::class)->rescale("d=5 9\r\n6 90\r\n52 2499790 0 0\r\n\r\n", 1, 10, 5000);

        $this->assertSame(['5 9', '6 9', '52 5000 0 0'], $out);
    }

    public function test_a_rounds_win_and_feature_come_from_its_steps(): void
    {
        $steps = [
            ['sent' => '1 10 1 10 1', 'reply' => "1 10 1 10 …\r\n2 5 1\r\n"],
            ['sent' => '2 6', 'reply' => "2 6\r\n3 0 62 620 1 1\r\n1 10 1 10 3 1 2\r\n2 5 1\r\n"],
            ['sent' => '4 0', 'reply' => "5 62\r\n6 620\r\n52 100 0 0\r\n"],
        ];

        $this->assertSame(62, PlayNGoRound::winOf($steps));
        $this->assertTrue(PlayNGoRound::hasFeature($steps));
        $this->assertSame(0, PlayNGoRound::winOf([['sent' => '1 10 1 10 1', 'reply' => "1 10 1 10 …\r\n6 0\r\n"]]));
    }

    public function test_messagepack_frames_round_trip(): void
    {
        $msg = MessagePack::encode([1, [], null, 'Commands', ["d=104 1\r\n52 2500000 0 0\r\n"], []]);
        $frames = MessagePack::frames(MessagePack::frame($msg, MessagePack::encode([6])));

        $this->assertSame([1, [], null, 'Commands', ["d=104 1\r\n52 2500000 0 0\r\n"], []], $frames[0]);
        $this->assertSame([6], $frames[1]);
    }
}
