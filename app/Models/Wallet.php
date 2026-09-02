<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player's money. One row per user (multi-currency deferred).
 *
 * ← the balance / bonus_* / count_* columns of legacy w_users.
 *
 * @property int $id
 * @property int $user_id
 * @property Currency $currency
 * @property numeric $balance
 * @property numeric $bonus_tournaments
 * @property numeric $bonus_happy_hours
 * @property numeric $bonus_refunds
 * @property numeric $bonus_progress
 * @property numeric $bonus_daily
 * @property numeric $bonus_invite
 * @property numeric $bonus_welcome
 * @property numeric $bonus_sms
 * @property numeric $bonus_wheel
 * @property numeric $wager_total
 * @property numeric $wager_tournaments
 * @property numeric $wager_happy_hours
 * @property numeric $wager_refunds
 * @property numeric $wager_progress
 * @property numeric $wager_daily
 * @property numeric $wager_invite
 * @property numeric $wager_welcome
 * @property numeric $wager_sms
 * @property numeric $wager_wheel
 * @property numeric $locked
 * @property numeric $total_deposited
 * @property numeric $total_withdrawn
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Database\Factories\WalletFactory factory($count = null, $state = [])
 * @method static Builder<static>|Wallet newModelQuery()
 * @method static Builder<static>|Wallet newQuery()
 * @method static Builder<static>|Wallet query()
 * @method static Builder<static>|Wallet whereBalance($value)
 * @method static Builder<static>|Wallet whereBonusDaily($value)
 * @method static Builder<static>|Wallet whereBonusHappyHours($value)
 * @method static Builder<static>|Wallet whereBonusInvite($value)
 * @method static Builder<static>|Wallet whereBonusProgress($value)
 * @method static Builder<static>|Wallet whereBonusRefunds($value)
 * @method static Builder<static>|Wallet whereBonusSms($value)
 * @method static Builder<static>|Wallet whereBonusTournaments($value)
 * @method static Builder<static>|Wallet whereBonusWelcome($value)
 * @method static Builder<static>|Wallet whereBonusWheel($value)
 * @method static Builder<static>|Wallet whereCreatedAt($value)
 * @method static Builder<static>|Wallet whereCurrency($value)
 * @method static Builder<static>|Wallet whereId($value)
 * @method static Builder<static>|Wallet whereLocked($value)
 * @method static Builder<static>|Wallet whereTotalDeposited($value)
 * @method static Builder<static>|Wallet whereTotalWithdrawn($value)
 * @method static Builder<static>|Wallet whereUpdatedAt($value)
 * @method static Builder<static>|Wallet whereUserId($value)
 * @method static Builder<static>|Wallet whereWagerDaily($value)
 * @method static Builder<static>|Wallet whereWagerHappyHours($value)
 * @method static Builder<static>|Wallet whereWagerInvite($value)
 * @method static Builder<static>|Wallet whereWagerProgress($value)
 * @method static Builder<static>|Wallet whereWagerRefunds($value)
 * @method static Builder<static>|Wallet whereWagerSms($value)
 * @method static Builder<static>|Wallet whereWagerTotal($value)
 * @method static Builder<static>|Wallet whereWagerTournaments($value)
 * @method static Builder<static>|Wallet whereWagerWelcome($value)
 * @method static Builder<static>|Wallet whereWagerWheel($value)
 *
 * @mixin \Eloquent
 */
class Wallet extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Bonus bucket => [balance column, wagering column]. */
    public const array BONUS_BUCKETS = [
        'tournaments' => ['bonus_tournaments', 'wager_tournaments'],
        'happy_hours' => ['bonus_happy_hours', 'wager_happy_hours'],
        'refunds' => ['bonus_refunds', 'wager_refunds'],
        'progress' => ['bonus_progress', 'wager_progress'],
        'daily' => ['bonus_daily', 'wager_daily'],
        'invite' => ['bonus_invite', 'wager_invite'],
        'welcome' => ['bonus_welcome', 'wager_welcome'],
        'sms' => ['bonus_sms', 'wager_sms'],
        'wheel' => ['bonus_wheel', 'wager_wheel'],
    ];

    protected function casts(): array
    {
        $casts = [
            'balance' => 'decimal:4',
            'currency' => Currency::class,
        ];

        foreach (self::BONUS_BUCKETS as [$balance, $wager]) {
            $casts[$balance] = 'decimal:4';
            $casts[$wager] = 'decimal:4';
        }

        return $casts + [
            'wager_total' => 'decimal:4',
            'locked' => 'decimal:4',
            'total_deposited' => 'decimal:4',
            'total_withdrawn' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Real balance plus every bonus bucket. */
    public function totalBalance(): string
    {
        $total = (float) $this->balance;

        foreach (self::BONUS_BUCKETS as [$balance]) {
            $total += (float) $this->{$balance};
        }

        return number_format($total, 4, '.', '');
    }
}
