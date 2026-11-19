<?php

namespace App\Models;

use App\Services\GamePlay\Protocol\IgtProtocol;
use Illuminate\Database\Eloquent\Model;

/**
 * An IGT game's recorded server setup: the paytable, init and money format
 * our {@see IgtProtocol} serves (patched).
 *
 * @property string $game_key
 * @property array<string, mixed> $paytable
 * @property array<string, mixed> $init
 * @property array<string, mixed> $currency
 */
class IgtSetup extends Model
{
    protected $table = 'igt_setups';

    protected $primaryKey = 'game_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paytable' => 'array', 'init' => 'array', 'currency' => 'array'];
    }
}
