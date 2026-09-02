<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One spin — the financial record.  ← w_stat_game (append-only, no updated_at).
 *
 * @property int $id
 * @property int $shop_id
 * @property int $user_id
 * @property int|null $game_id
 * @property string $game_code
 * @property Currency $currency
 * @property numeric $bet
 * @property numeric $win
 * @property numeric $balance_after
 * @property numeric $stake_to_bank
 * @property numeric $stake_to_jackpot
 * @property numeric $stake_to_profit
 * @property numeric $denomination
 * @property array<array-key, mixed>|null $bank_snapshot
 * @property int $status
 * @property Carbon $played_at
 * @property-read Game|null $game
 * @property-read Shop $shop
 * @property-read User|null $user
 *
 * @method static Builder<static>|GameRound newModelQuery()
 * @method static Builder<static>|GameRound newQuery()
 * @method static Builder<static>|GameRound query()
 * @method static Builder<static>|GameRound visibleTo(?User $viewer)
 * @method static Builder<static>|GameRound whereBalanceAfter($value)
 * @method static Builder<static>|GameRound whereBankSnapshot($value)
 * @method static Builder<static>|GameRound whereBet($value)
 * @method static Builder<static>|GameRound whereCurrency($value)
 * @method static Builder<static>|GameRound whereDenomination($value)
 * @method static Builder<static>|GameRound whereGameCode($value)
 * @method static Builder<static>|GameRound whereGameId($value)
 * @method static Builder<static>|GameRound whereId($value)
 * @method static Builder<static>|GameRound wherePlayedAt($value)
 * @method static Builder<static>|GameRound whereShopId($value)
 * @method static Builder<static>|GameRound whereStakeToBank($value)
 * @method static Builder<static>|GameRound whereStakeToJackpot($value)
 * @method static Builder<static>|GameRound whereStakeToProfit($value)
 * @method static Builder<static>|GameRound whereStatus($value)
 * @method static Builder<static>|GameRound whereUserId($value)
 * @method static Builder<static>|GameRound whereWin($value)
 *
 * @mixin \Eloquent
 */
class GameRound extends Model
{
    use ScopedToShopHierarchy;

    public const ?string UPDATED_AT = null;

    public const string CREATED_AT = 'played_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'bet' => 'decimal:4',
            'win' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'stake_to_bank' => 'decimal:4',
            'stake_to_jackpot' => 'decimal:4',
            'stake_to_profit' => 'decimal:4',
            'denomination' => 'decimal:4',
            'bank_snapshot' => 'array',
            'played_at' => 'datetime',
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
