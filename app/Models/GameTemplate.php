<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use App\Enums\Volatility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Storage;

/**
 * The master game catalogue — one row per installed game package.
 *
 * ← legacy w_games (shop_id = 0) + w_game_path.
 *
 * @property int $id
 * @property string $code
 * @property string $title
 * @property string|null $poster_path
 * @property GameEngine $engine
 * @property string|null $package_path
 * @property string|null $client_path
 * @property Device $device
 * @property BankType $bank_type
 * @property ClientProtocol|null $client_protocol
 * @property int $min_match
 * @property array<array-key, mixed>|null $bonus_config
 * @property array<array-key, mixed>|null $default_bet_options
 * @property numeric $default_denomination
 * @property array<array-key, mixed>|null $default_lines_config
 * @property array<array-key, mixed>|null $default_jackpot_chances
 * @property array<array-key, mixed>|null $default_advanced
 * @property ScaleMode|null $scale_mode
 * @property ViewState|null $view_state
 * @property int $reel_count
 * @property int $row_count
 * @property int $symbol_count
 * @property array<array-key, mixed>|null $symbols
 * @property int|null $wild_symbol
 * @property int|null $scatter_symbol
 * @property int|null $bonus_symbol
 * @property int $wild_multiplier
 * @property bool $has_bonus
 * @property int $bonus_type
 * @property int $scatter_type
 * @property bool $has_free_spins
 * @property int $free_spins_count
 * @property array<array-key, mixed>|null $free_spins_table
 * @property int $free_spins_multiplier
 * @property bool $has_gamble
 * @property int $gamble_type
 * @property int $gamble_win_chance
 * @property bool $split_screen
 * @property Volatility $volatility
 * @property int $rtp_control_window
 * @property array<array-key, mixed>|null $paytable
 * @property array<array-key, mixed>|null $reel_strips
 * @property array<array-key, mixed>|null $paylines
 * @property array<array-key, mixed>|null $win_chances
 * @property array<array-key, mixed>|null $win_distribution
 * @property array<array-key, mixed>|null $rtp_control
 * @property array<array-key, mixed>|null $layout
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GameBundle|null $activeBundle
 * @property-read Collection<int, GameBundle> $bundles
 * @property-read int|null $bundles_count
 * @property-read Collection<int, Game> $games
 * @property-read int|null $games_count
 * @property-read BaseCollection<int, Category> $categories
 *
 * @method static Builder<static>|GameTemplate newModelQuery()
 * @method static Builder<static>|GameTemplate newQuery()
 * @method static Builder<static>|GameTemplate query()
 * @method static Builder<static>|GameTemplate whereBankType($value)
 * @method static Builder<static>|GameTemplate whereBonusSymbol($value)
 * @method static Builder<static>|GameTemplate whereBonusType($value)
 * @method static Builder<static>|GameTemplate whereClientPath($value)
 * @method static Builder<static>|GameTemplate whereCode($value)
 * @method static Builder<static>|GameTemplate whereCreatedAt($value)
 * @method static Builder<static>|GameTemplate whereDefaultAdvanced($value)
 * @method static Builder<static>|GameTemplate whereDefaultBetOptions($value)
 * @method static Builder<static>|GameTemplate whereDefaultDenomination($value)
 * @method static Builder<static>|GameTemplate whereDefaultJackpotChances($value)
 * @method static Builder<static>|GameTemplate whereDefaultLinesConfig($value)
 * @method static Builder<static>|GameTemplate whereDevice($value)
 * @method static Builder<static>|GameTemplate whereEngine($value)
 * @method static Builder<static>|GameTemplate whereFreeSpinsCount($value)
 * @method static Builder<static>|GameTemplate whereFreeSpinsMultiplier($value)
 * @method static Builder<static>|GameTemplate whereFreeSpinsTable($value)
 * @method static Builder<static>|GameTemplate whereGambleType($value)
 * @method static Builder<static>|GameTemplate whereGambleWinChance($value)
 * @method static Builder<static>|GameTemplate whereHasBonus($value)
 * @method static Builder<static>|GameTemplate whereHasFreeSpins($value)
 * @method static Builder<static>|GameTemplate whereHasGamble($value)
 * @method static Builder<static>|GameTemplate whereId($value)
 * @method static Builder<static>|GameTemplate whereIsActive($value)
 * @method static Builder<static>|GameTemplate whereLayout($value)
 * @method static Builder<static>|GameTemplate wherePackagePath($value)
 * @method static Builder<static>|GameTemplate wherePaylines($value)
 * @method static Builder<static>|GameTemplate wherePaytable($value)
 * @method static Builder<static>|GameTemplate whereReelCount($value)
 * @method static Builder<static>|GameTemplate whereReelStrips($value)
 * @method static Builder<static>|GameTemplate whereRowCount($value)
 * @method static Builder<static>|GameTemplate whereRtpControl($value)
 * @method static Builder<static>|GameTemplate whereRtpControlWindow($value)
 * @method static Builder<static>|GameTemplate whereScaleMode($value)
 * @method static Builder<static>|GameTemplate whereScatterSymbol($value)
 * @method static Builder<static>|GameTemplate whereScatterType($value)
 * @method static Builder<static>|GameTemplate whereSplitScreen($value)
 * @method static Builder<static>|GameTemplate whereSymbolCount($value)
 * @method static Builder<static>|GameTemplate whereSymbols($value)
 * @method static Builder<static>|GameTemplate whereTitle($value)
 * @method static Builder<static>|GameTemplate whereUpdatedAt($value)
 * @method static Builder<static>|GameTemplate whereViewState($value)
 * @method static Builder<static>|GameTemplate whereVolatility($value)
 * @method static Builder<static>|GameTemplate whereWildMultiplier($value)
 * @method static Builder<static>|GameTemplate whereWildSymbol($value)
 * @method static Builder<static>|GameTemplate whereWinChances($value)
 * @method static Builder<static>|GameTemplate whereWinDistribution($value)
 *
 * @mixin \Eloquent
 */
class GameTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'engine' => GameEngine::class,
            'device' => Device::class,
            'bank_type' => BankType::class,
            'client_protocol' => ClientProtocol::class,
            'bonus_config' => 'array',
            'scale_mode' => ScaleMode::class,
            'view_state' => ViewState::class,
            'volatility' => Volatility::class,
            'default_bet_options' => 'array',
            'default_lines_config' => 'array',
            'default_jackpot_chances' => 'array',
            'default_advanced' => 'array',
            'default_denomination' => 'decimal:4',
            'has_bonus' => 'boolean',
            'has_free_spins' => 'boolean',
            'has_gamble' => 'boolean',
            'split_screen' => 'boolean',
            'symbols' => 'array',
            'paytable' => 'array',
            'reel_strips' => 'array',
            'paylines' => 'array',
            'free_spins_table' => 'array',
            'win_chances' => 'array',
            'win_distribution' => 'array',
            'rtp_control' => 'array',
            'layout' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'template_id');
    }

    /**
     * Categories this template's games belong to — inherited upward from the
     * per-shop {@see Game} rows (categories attach to games, never to the master
     * template, and a template's games can span several shops). Read-only;
     * eager-load `games.categories` where this is listed to avoid N+1.
     *
     * @return BaseCollection<int, Category>
     */
    public function getCategoriesAttribute(): BaseCollection
    {
        $this->loadMissing('games.categories');

        return $this->games
            ->flatMap(fn (Game $game) => $game->categories)
            ->unique('id')
            ->sortBy('title')
            ->values();
    }

    public function bundles(): HasMany
    {
        return $this->hasMany(GameBundle::class)->orderByDesc('version');
    }

    public function activeBundle(): HasOne
    {
        return $this->hasOne(GameBundle::class)->where('is_active', true);
    }

    public function hasBundle(): bool
    {
        return $this->activeBundle()->exists();
    }

    /** Public URL of the lobby/admin poster, or null if none is set. */
    public function posterUrl(): ?string
    {
        return $this->poster_path
            ? Storage::disk('public')->url($this->poster_path)
            : null;
    }
}
