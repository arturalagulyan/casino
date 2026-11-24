<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Wazdan demo session — the outcome library a
 * Wazdan game's spins are served from: the paid START's reply, then one per
 * free game / respin it awarded (each a `res` object).
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array<string, mixed>> $steps
 */
class WazdanRound extends Model
{
    protected $table = 'wazdan_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
