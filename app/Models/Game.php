<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\GameLabel;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A game template published into one shop, with that shop's tuning.
 * ← legacy w_games (shop_id > 0).
 */
class Game extends Model
{
    use ScopedToShopHierarchy;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'label' => GameLabel::class,
            'bank_type' => BankType::class,
            'scale_mode' => ScaleMode::class,
            'view_state' => ViewState::class,
            'lines_config_spin' => 'array',
            'lines_config_spin_bonus' => 'array',
            'lines_config_bonus' => 'array',
            'lines_config_bonus_bonus' => 'array',
            'jackpot_chances' => 'array',
            'advanced' => 'array',
            'bet_options' => 'array',
            'denomination' => 'decimal:4',
            'is_visible' => 'boolean',
            'total_bet' => 'decimal:4',
            'total_win' => 'decimal:4',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GameTemplate::class, 'template_id');
    }

    public function jackpot(): BelongsTo
    {
        return $this->belongsTo(Jackpot::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(GameRound::class);
    }

    /** The pool this game settles against for the given shop currency. */
    public function bank(): ?GameBank
    {
        return $this->shop?->bank($this->shop->currency);
    }
}
