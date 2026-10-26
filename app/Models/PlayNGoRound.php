<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from Play'n GO's practice server — the outcome
 * library a Play'n GO game's spins are served from.
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_coins
 * @property int $win_coins
 * @property bool $feature
 * @property list<array{sent: string, reply: string}> $steps
 */
class PlayNGoRound extends Model
{
    protected $table = 'playngo_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_coins' => 'integer', 'win_coins' => 'integer'];
    }

    /** A round's total in coins, from its collect reply (`5 <coins>`); 0 for a losing round. */
    public static function winOf(array $steps): int
    {
        foreach ($steps as $s) {
            if ($s['sent'] === '4 0' && preg_match('/^5 (\d+)/m', $s['reply'], $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** Whether a round brought a second board (the portal's free round). */
    public static function hasFeature(array $steps): bool
    {
        foreach (array_slice($steps, 1) as $s) {
            if (preg_match('/^1 \d+ \d+ \d+ /m', $s['reply'])) {
                return true;
            }
        }

        return false;
    }
}
