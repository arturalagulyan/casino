<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from an Apollo Games demo session — the outcome
 * library an Apollo game's spins are served from: the paid spin's
 * `casino.spin` reply, then one per free spin it awarded.
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array<string, mixed>> $steps
 */
class ApolloRound extends Model
{
    protected $table = 'apollo_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
