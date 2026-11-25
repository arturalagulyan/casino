<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from a Playtech demo session — the outcome library a
 * Playtech game's spins are served from: one step per spin request the client
 * made (the paid spin, then its free spins / respins), each the spin type it
 * sent (`t`: regular, free, re) and the messages the server answered with
 * (`m`: the game's spin response and any feature pushes; balance updates left out).
 *
 * @property int $id
 * @property string $game_key
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_credit
 * @property string|null $stage  the level the round was played on (Batman Begins: temple, manor …)
 * @property string|null $next_stage  the stage the recording moved on to right after it
 * @property list<array{t: string, m: list<array<string, mixed>>}> $steps
 */
class PlaytechRound extends Model
{
    protected $table = 'playtech_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_credit' => 'integer'];
    }
}
