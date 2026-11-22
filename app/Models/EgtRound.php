<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One whole round recorded from an EGT demo session — the outcome library
 * an EGT game's spins are served from: the paid spin's `bet` reply, then one
 * per free spin / bonus step it led to (each answered while the server's
 * state was not yet `idle` / `gamble`).
 *
 * @property int $id
 * @property int $gin
 * @property int $bet_cents
 * @property int $win_cents
 * @property bool $feature
 * @property int $start_balance
 * @property list<array<string, mixed>> $steps
 */
class EgtRound extends Model
{
    protected $table = 'egt_rounds';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['steps' => 'array', 'feature' => 'boolean', 'gin' => 'integer', 'bet_cents' => 'integer', 'win_cents' => 'integer', 'start_balance' => 'integer'];
    }
}
