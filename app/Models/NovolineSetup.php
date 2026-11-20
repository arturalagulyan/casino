<?php

namespace App\Models;

use App\Services\GamePlay\Protocol\NovolineProtocol;
use Illuminate\Database\Eloquent\Model;

/**
 * A Novoline game's recorded login reply (config + init state frames) that
 * {@see NovolineProtocol} serves, patched.
 *
 * @property string $game_key
 * @property list<string> $login
 */
class NovolineSetup extends Model
{
    protected $table = 'novoline_setups';

    protected $primaryKey = 'game_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['login' => 'array'];
    }
}
