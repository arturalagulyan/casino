<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a NetEnt demo session — the outcome library
 * a NetEnt game's spins are served from. Recorded at bet level 1 and coin
 * value 1 (cent), so coins and cents in the replies are the same numbers.
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_coins
 * @property int $win_coins
 * @property bool $feature
 * @property int $start_credit
 * @property list<array{sent: string, reply: string}> $steps
 */
class NetEntRound extends Model
{
    protected $table = 'netent_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_coins' => 'integer', 'win_coins' => 'integer', 'start_credit' => 'integer'];
    }

    /** @return array<string, string> a servlet reply's fields */
    public static function fields(string $reply): array
    {
        // not parse_str(): it mangles the dotted keys (`totalwin.coins` → `totalwin_coins`)
        $out = [];
        foreach (explode('&', trim($reply)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[urldecode($k)] = urldecode($v);
        }

        return $out;
    }

    /**
     * Bet lines an init reply names. `bet.betlines` is a count in some games
     * (Happy Riches: `30`) and the line ids in others (Wild Turkey: `0,1,…,19`).
     *
     * @param  array<string, string>  $init
     */
    public static function lineCount(array $init): int
    {
        $lines = trim((string) ($init['bet.betlines'] ?? ''));

        return str_contains($lines, ',') ? count(explode(',', $lines)) : (int) $lines;
    }

    /** A round's total in coins: its last reply's `totalwin.coins`. */
    public static function winOf(array $steps): int
    {
        $last = end($steps);

        return $last ? (int) (self::fields($last['reply'])['totalwin.coins'] ?? 0) : 0;
    }
}
