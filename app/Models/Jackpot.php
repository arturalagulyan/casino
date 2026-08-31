<?php

namespace App\Models;

use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jackpot pool.  ← w_jpg */
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
