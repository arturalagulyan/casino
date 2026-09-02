<?php

namespace App\Models;

use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Jackpot pool.  ← w_jpg
 *
 * @property int $id
 * @property int|null $shop_id
 * @property string $name
 * @property numeric $balance
 * @property numeric $contribution_percent
 * @property numeric $seed_min
 * @property numeric $seed_max
 * @property numeric $payout_min
 * @property numeric $payout_max
 * @property int|null $last_winner_id
 * @property Carbon|null $last_won_at
 * @property numeric|null $last_won_amount
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $lastWinner
 * @property-read Shop|null $shop
 * @property-read Collection<int, JackpotWin> $wins
 * @property-read int|null $wins_count
 *
 * @method static Builder<static>|Jackpot newModelQuery()
 * @method static Builder<static>|Jackpot newQuery()
 * @method static Builder<static>|Jackpot query()
 * @method static Builder<static>|Jackpot visibleTo(?User $viewer)
 * @method static Builder<static>|Jackpot whereBalance($value)
 * @method static Builder<static>|Jackpot whereContributionPercent($value)
 * @method static Builder<static>|Jackpot whereCreatedAt($value)
 * @method static Builder<static>|Jackpot whereId($value)
 * @method static Builder<static>|Jackpot whereIsActive($value)
 * @method static Builder<static>|Jackpot whereLastWinnerId($value)
 * @method static Builder<static>|Jackpot whereLastWonAmount($value)
 * @method static Builder<static>|Jackpot whereLastWonAt($value)
 * @method static Builder<static>|Jackpot whereName($value)
 * @method static Builder<static>|Jackpot wherePayoutMax($value)
 * @method static Builder<static>|Jackpot wherePayoutMin($value)
 * @method static Builder<static>|Jackpot whereSeedMax($value)
 * @method static Builder<static>|Jackpot whereSeedMin($value)
 * @method static Builder<static>|Jackpot whereShopId($value)
 * @method static Builder<static>|Jackpot whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Jackpot extends Model
{
    use ScopedToShopHierarchy;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:6',
            'contribution_percent' => 'decimal:2',
            'seed_min' => 'decimal:4',
            'seed_max' => 'decimal:4',
            'payout_min' => 'decimal:4',
            'payout_max' => 'decimal:4',
            'last_won_at' => 'datetime',
            'last_won_amount' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function lastWinner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_winner_id');
    }

    public function wins(): HasMany
    {
        return $this->hasMany(JackpotWin::class);
    }
}
