<?php

namespace Tests\Unit;

use App\Services\GamePlay\Microgaming\MicrogamingFamily;
use PHPUnit\Framework\TestCase;

/**
 * What the recorder sends after a reply — each case is a real game's reply shape.
 */
class MicrogamingFamilyNextTest extends TestCase
{
    public function test_an_idle_game_ignores_a_dormant_bonus(): void
    {
        // Eagles Wings: every Refresh lists LuckyEagleBonus3 at state 1 — it takes no pick
        $reply = '<Pkt><Response><Framework state="0"/><Slot state="0"/><BonusGames><Bonus id="0" state="1" items="0,1,2"/></BonusGames></Response></Pkt>';
        $this->assertNull(MicrogamingFamily::next($reply));
    }

    public function test_a_waiting_pick_is_made(): void
    {
        $reply = '<Pkt><Response><Framework state="2"/><Slot state="0"/><BonusGames><Bonus id="0" state="1" items="0,1,2"/></BonusGames></Response></Pkt>';
        [$verb, $attrs] = MicrogamingFamily::next($reply);
        $this->assertSame('BonusPick', $verb);
        $this->assertContains($attrs['item'], ['0', '1', '2']);
    }

    public function test_after_the_pick_the_awarded_free_spins_are_played(): void
    {
        // Monster Wheels: the pick's reply still lists the bonus; Slot state 1 = free spins awarded
        $reply = '<Pkt><Response><Framework state="3"/><Slot state="1"><FreeSpinAwards freeSpinNum="6"/></Slot><BonusGames><Bonus id="0" state="1" items="0,1,2"/></BonusGames></Response></Pkt>';
        $this->assertSame(['FreeSpin', []], MicrogamingFamily::next($reply));
    }

    public function test_an_unlisted_choice_is_picked_when_the_game_has_one(): void
    {
        // King Tusk: "choose your free spins bonus" — Framework state 2, nothing listed; BonusPick item 0–2
        $reply = '<Pkt><Response><Framework state="2"/><Slot state="0"/><BonusGames lastBonusPlayed="-1"/></Response></Pkt>';
        $this->assertNull(MicrogamingFamily::next($reply));
        [$verb, $attrs] = MicrogamingFamily::next($reply, null, 3);
        $this->assertSame('BonusPick', $verb);
        $this->assertContains($attrs['item'], ['0', '1', '2']);
    }

    public function test_a_finished_round_asks_for_nothing(): void
    {
        $reply = '<Pkt><Response><Framework state="0"/><Slot state="0"/><NextSpin freeSpinsRemaining="0"/></Response></Pkt>';
        $this->assertNull(MicrogamingFamily::next($reply, null, 3));
    }
}
