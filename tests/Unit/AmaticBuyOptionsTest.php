<?php

namespace Tests\Unit;

use App\Services\GamePlay\Amatic\AmaticClientMath;
use PHPUnit\Framework\TestCase;

/**
 * Bonus-buy prices read from a gmsl slot's init tail, following the game's
 * own tail parser (shapes as in All Ways Candy and Wild Shark Bonus Buy).
 */
class AmaticBuyOptionsTest extends TestCase
{
    /** The client's hex readers: 1 digit, 2 digits, length-prefixed, strip (length-prefixed count of 1-digit symbols). */
    private const string READERS = 'tnV.prototype.tNE=function(a){a=parseInt(a.charAt(this.tn7),16);this.tn7++;return a};'
        .'tnV.prototype.tNY=function(a){var b=a.charAt(this.tn7);b+=a.charAt(this.tn7+1);this.tn7+=2;return parseInt(b,16)};'
        .'tnV.prototype.tNB=function(a){for(var b=this.tNI(a),c=[],d=0;d<b;d++)c[d]=parseInt(a.charAt(this.tn7),16),this.tn7+=1;return c};'
        .'tnV.prototype.tNI=function(a){var b=parseInt(a.charAt(this.tn7),16),c="";this.tn7++;for(var d=0;d<b;d++)c+=a.charAt(this.tn7),this.tn7+=1;return parseInt(c,16)};'
        .'tu2.prototype.tla=function(a){return this.tNB(a)};';

    private const string DIALOG = 'this.ts8.tv6({values:[this.t43(this.t4K()*this.tjw[0])],tsk:this.tNO("buy_bonusspins",!0)});';

    public function test_reads_the_prices_after_a_prelude_of_strips(): void
    {
        $js = self::READERS.self::DIALOG
            .'tP6.prototype.tlo=function(a){var b;if(0==this.tnS){for(b=this.tuc-2;b<this.tuc;b++)this.tP3(this.tuG[b]);'
            .'this.rl=this.tNE(a);for(b=0;b<this.rl;b++)this.tPV[b]=this.tla(a);this.tgt=this.tNI(a);'
            .'b=0;for(var c=this.tNY(a),d;b<c;b++)d=this.tNI(a),0<d&&this.tjw.push(d);this.tgU=[this.tNI(a)/100]}};';
        // 2 strips (3 and 2 symbols) · cap 10000 · 1 price: 100 · booster ×1.00 · per-frame fields
        $tail = '2'.'13012'.'1245'.'42710'.'01'.'264'.'264'.'0f10';

        $this->assertSame(['prices' => [100], 'cap' => 10000], (new AmaticClientMath)->buyOptions($js, $tail));
    }

    public function test_reads_the_prices_at_the_start_of_the_tail(): void
    {
        $js = self::READERS.str_replace('tjw', 'W36', self::DIALOG)
            .'WIB.prototype.Wea=function(a){if(0==this.Wvb){this.Wl6=this.tNI(a);'
            .'for(var b=0,c=this.tNY(a),d;b<c;b++)d=this.tNI(a),0<d&&this.W36.push(d)}WSJ.prototype.Wea.call(this,a)};';

        $this->assertSame(['prices' => [100, 400], 'cap' => 0], (new AmaticClientMath)->buyOptions($js, '0'.'03'.'264'.'0'.'3190'.'10'));
    }

    public function test_no_buy_dialog_or_no_positive_price_means_no_buy(): void
    {
        $math = new AmaticClientMath;

        $this->assertNull($math->buyOptions(self::READERS, '0'.'01'.'264'));
        $js = self::READERS.self::DIALOG
            .'X.prototype.tlo=function(a){if(0==this.tnS){this.tgt=this.tNI(a);for(var b=0,c=this.tNY(a),d;b<c;b++)d=this.tNI(a),0<d&&this.tjw.push(d)}};';
        $this->assertNull($math->buyOptions($js, '0'.'01'.'0'));
    }
}
