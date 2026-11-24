<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Pragmatic Play demo session — the outcome
 * library a Pragmatic game's spins are served from: the paid doSpin's reply,
 * then one per request the round went on with (doSpin for tumbles / free
 * spins, doCollect, doBonus, doCollectBonus, …).
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array{a: string, p: array<string, mixed>, r: string}> $steps
 */
class PragmaticRound extends Model
{
    protected $table = 'pragmatic_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
