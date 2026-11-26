<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Microgaming demo session on Betboy — the
 * outcome library a Microgaming game's spins are served from: the paid Spin
 * packet's reply, then one per packet the round went on with (BonusPick,
 * FreeSpin, …).
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array{q: string, p: array<string, string>, r: string}> $steps
 */
class MicrogamingRound extends Model
{
    protected $table = 'microgaming_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
