<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Booongo demo session — the outcome
 * library a Booongo game's spins are served from: the paid spin's
 * `play` reply, then one per action the round went on with (freespin_init,
 * freespin, bonus_init, respin, …stop).
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array<string, mixed>> $steps
 */
class BooongoRound extends Model
{
    protected $table = 'booongo_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
