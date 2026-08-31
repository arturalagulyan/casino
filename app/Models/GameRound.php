<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One spin — the financial record.  ← w_stat_game (append-only, no updated_at). */
class GameRound extends Model
{
    use ScopedToShopHierarchy;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'played_at';

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
