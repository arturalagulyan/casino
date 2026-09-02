<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $jackpot_id
 * @property int $user_id
 * @property int|null $shop_id
 * @property int|null $game_id
 * @property int|null $round_id
 * @property numeric $amount
 * @property numeric $balance_before
 * @property Carbon $won_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game|null $game
 * @property-read Jackpot $jackpot
 * @property-read GameRound|null $round
 * @property-read Shop|null $shop
 * @property-read User|null $user
 *
 * @method static Builder<static>|JackpotWin newModelQuery()
 * @method static Builder<static>|JackpotWin newQuery()
 * @method static Builder<static>|JackpotWin query()
 * @method static Builder<static>|JackpotWin whereAmount($value)
 * @method static Builder<static>|JackpotWin whereBalanceBefore($value)
 * @method static Builder<static>|JackpotWin whereCreatedAt($value)
 * @method static Builder<static>|JackpotWin whereGameId($value)
 * @method static Builder<static>|JackpotWin whereId($value)
 * @method static Builder<static>|JackpotWin whereJackpotId($value)
 * @method static Builder<static>|JackpotWin whereRoundId($value)
 * @method static Builder<static>|JackpotWin whereShopId($value)
 * @method static Builder<static>|JackpotWin whereUpdatedAt($value)
 * @method static Builder<static>|JackpotWin whereUserId($value)
 * @method static Builder<static>|JackpotWin whereWonAt($value)
 *
 * @mixin \Eloquent
 */
class JackpotWin extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:6',
            'won_at' => 'datetime',
        ];
    }

    public function jackpot(): BelongsTo
    {
        return $this->belongsTo(Jackpot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(GameRound::class, 'round_id');
    }
}
