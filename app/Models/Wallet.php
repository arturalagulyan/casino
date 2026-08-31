<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's money. One row per user (multi-currency deferred).
 * ← the balance / bonus_* / count_* columns of legacy w_users.
 */
class Wallet extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Bonus bucket => [balance column, wagering column]. */
    public const BONUS_BUCKETS = [
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
