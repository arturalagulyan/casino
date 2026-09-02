<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Active game browser-tab per user.  ← w_subsessions
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $game_id
 * @property string $token
 * @property bool $is_active
 * @property array<array-key, mixed>|null $state
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game|null $game
 * @property-read User|null $user
 *
 * @method static Builder<static>|GameSession newModelQuery()
 * @method static Builder<static>|GameSession newQuery()
 * @method static Builder<static>|GameSession query()
 * @method static Builder<static>|GameSession whereCreatedAt($value)
 * @method static Builder<static>|GameSession whereGameId($value)
 * @method static Builder<static>|GameSession whereId($value)
 * @method static Builder<static>|GameSession whereIsActive($value)
 * @method static Builder<static>|GameSession whereLastSeenAt($value)
 * @method static Builder<static>|GameSession whereState($value)
 * @method static Builder<static>|GameSession whereToken($value)
 * @method static Builder<static>|GameSession whereUpdatedAt($value)
 * @method static Builder<static>|GameSession whereUserId($value)
 *
 * @mixin \Eloquent
 */
class GameSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'state' => 'array',
            'last_seen_at' => 'datetime',
        ];
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
