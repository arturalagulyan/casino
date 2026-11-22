<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Merkur (Edict) demo session — the outcome
 * library a Merkur game's spins are served from: the paid PLAY's reply, one
 * per free game it awarded, then the FINISH_GAME that collected its win.
 * Each step is {action, core, game} — the request's gameActionId, the
 * reply's coreData and its decoded gameData.
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array{action: string, core: array<string, mixed>, game: array<string, mixed>}> $steps
 */
class MerkurRound extends Model
{
    protected $table = 'merkur_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
