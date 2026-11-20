<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Betboy Novoline (Greentube) demo session —
 * the outcome library a Novoline game's spins are served from: each step is
 * the client frame sent (`30` spin, `60` free spin, `40` collect…) and the
 * server frames it answered.
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property list<array{c: string, r: list<string>}> $steps
 */
class NovolineRound extends Model
{
    protected $table = 'novoline_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
