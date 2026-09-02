<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw per-round payload.  ← w_game_log (retention-managed, created_at only).
 *
 * @property int $id
 * @property int $shop_id
 * @property int $user_id
 * @property int|null $game_id
 * @property string|null $ip
 * @property string|null $payload
 * @property Carbon $created_at
 * @property-read Game|null $game
 * @property-read Shop $shop
 * @property-read User|null $user
 *
 * @method static Builder<static>|GameLog newModelQuery()
 * @method static Builder<static>|GameLog newQuery()
 * @method static Builder<static>|GameLog query()
 * @method static Builder<static>|GameLog whereCreatedAt($value)
 * @method static Builder<static>|GameLog whereGameId($value)
 * @method static Builder<static>|GameLog whereId($value)
 * @method static Builder<static>|GameLog whereIp($value)
 * @method static Builder<static>|GameLog wherePayload($value)
 * @method static Builder<static>|GameLog whereShopId($value)
 * @method static Builder<static>|GameLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
class GameLog extends Model
{
    public const ?string UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
