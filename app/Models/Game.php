<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Enums\GameLabel;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use App\Models\Concerns\ScopedToShopHierarchy;
use App\Services\GamePlay\GameConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A game template published into one shop, with that shop's tuning.
 *
 * ← legacy w_games (shop_id > 0).
 *
 * @property int $id
 * @property int $shop_id
 * @property int $template_id
 * @property int|null $jackpot_id
 * @property string|null $title
 * @property GameLabel|null $label
 * @property BankType $bank_type
 * @property int|null $rtp_percent
 * @property int|null $max_win_multiplier
 * @property int|null $wild_multiplier
 * @property int|null $free_spins_count
 * @property array<array-key, mixed>|null $free_spins_table
 * @property array<array-key, mixed>|null $win_distribution
 * @property int $reserve_percent
 * @property int $cask
 * @property array<array-key, mixed>|null $lines_config_spin
 * @property array<array-key, mixed>|null $lines_config_spin_bonus
 * @property array<array-key, mixed>|null $lines_config_bonus
 * @property array<array-key, mixed>|null $lines_config_bonus_bonus
 * @property array<array-key, mixed>|null $win_chances
 * @property array<array-key, mixed>|null $jackpot_chances
 * @property array<array-key, mixed>|null $advanced
 * @property array<array-key, mixed>|null $engine_state
 * @property array<array-key, mixed>|null $bet_options
 * @property numeric $denomination
 * @property Currency|null $pricing_currency
 * @property ScaleMode|null $scale_mode
 * @property ViewState|null $view_state
 * @property bool $is_visible
 * @property int $sort_order
 * @property numeric $total_bet
 * @property numeric $total_win
 * @property-read int|null $rounds_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read Jackpot|null $jackpot
 * @property-read Collection<int, GameRound> $rounds
 * @property-read Shop $shop
 * @property-read GameTemplate $template
 *
 * @method static Builder<static>|Game newModelQuery()
 * @method static Builder<static>|Game newQuery()
 * @method static Builder<static>|Game query()
 * @method static Builder<static>|Game visibleTo(?User $viewer)
 * @method static Builder<static>|Game whereAdvanced($value)
 * @method static Builder<static>|Game whereBankType($value)
 * @method static Builder<static>|Game whereBetOptions($value)
 * @method static Builder<static>|Game whereCask($value)
 * @method static Builder<static>|Game whereCreatedAt($value)
 * @method static Builder<static>|Game whereDenomination($value)
 * @method static Builder<static>|Game whereEngineState($value)
 * @method static Builder<static>|Game whereFreeSpinsCount($value)
 * @method static Builder<static>|Game whereFreeSpinsTable($value)
 * @method static Builder<static>|Game whereId($value)
 * @method static Builder<static>|Game whereIsVisible($value)
 * @method static Builder<static>|Game whereJackpotChances($value)
 * @method static Builder<static>|Game whereJackpotId($value)
 * @method static Builder<static>|Game whereLabel($value)
 * @method static Builder<static>|Game whereLinesConfigBonus($value)
 * @method static Builder<static>|Game whereLinesConfigBonusBonus($value)
 * @method static Builder<static>|Game whereLinesConfigSpin($value)
 * @method static Builder<static>|Game whereLinesConfigSpinBonus($value)
 * @method static Builder<static>|Game whereMaxWinMultiplier($value)
 * @method static Builder<static>|Game wherePricingCurrency($value)
 * @method static Builder<static>|Game whereReservePercent($value)
 * @method static Builder<static>|Game whereRoundsCount($value)
 * @method static Builder<static>|Game whereRtpPercent($value)
 * @method static Builder<static>|Game whereScaleMode($value)
 * @method static Builder<static>|Game whereShopId($value)
 * @method static Builder<static>|Game whereSortOrder($value)
 * @method static Builder<static>|Game whereTemplateId($value)
 * @method static Builder<static>|Game whereTitle($value)
 * @method static Builder<static>|Game whereTotalBet($value)
 * @method static Builder<static>|Game whereTotalWin($value)
 * @method static Builder<static>|Game whereUpdatedAt($value)
 * @method static Builder<static>|Game whereViewState($value)
 * @method static Builder<static>|Game whereWildMultiplier($value)
 * @method static Builder<static>|Game whereWinChances($value)
 * @method static Builder<static>|Game whereWinDistribution($value)
 *
 * @mixin \Eloquent
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
            'free_spins_table' => 'array',
            'win_chances' => 'array',
            'win_distribution' => 'array',
            'jackpot_chances' => 'array',
            'advanced' => 'array',
            'engine_state' => 'array',
            'bet_options' => 'array',
            'denomination' => 'decimal:4',
            'pricing_currency' => Currency::class,
            'is_visible' => 'boolean',
            'total_bet' => 'decimal:4',
            'total_win' => 'decimal:4',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<GameTemplate, $this> */
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
        return $this->shop->bank($this->shop->currency);
    }

    /** Merged engine spec: template defaults with this game's per-shop overrides. */
    public function config(): GameConfig
    {
        return new GameConfig($this->template()->firstOrFail(), $this);
    }
}
