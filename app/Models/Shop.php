<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\GameOrder;
use App\Enums\ShopStatus;
use App\Support\Hierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $frontend
 * @property Currency $currency
 * @property numeric $balance
 * @property ShopStatus $status
 * @property int $rtp_percent
 * @property int $max_win_multiplier
 * @property numeric $player_limit
 * @property GameOrder $order_by
 * @property int|null $owner_id
 * @property array<array-key, mixed>|null $allowed_countries
 * @property array<array-key, mixed>|null $allowed_os
 * @property array<array-key, mixed>|null $allowed_devices
 * @property array<array-key, mixed>|null $required_rules
 * @property bool $happy_hours_enabled
 * @property bool $progress_enabled
 * @property bool $invites_enabled
 * @property bool $welcome_bonuses_enabled
 * @property bool $sms_bonuses_enabled
 * @property bool $wheel_fortune_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ApiKey> $apiKeys
 * @property-read int|null $api_keys_count
 * @property-read Collection<int, GameBank> $banks
 * @property-read int|null $banks_count
 * @property-read Collection<int, Category> $categories
 * @property-read int|null $categories_count
 * @property-read Collection<int, Game> $games
 * @property-read int|null $games_count
 * @property-read Collection<int, Jackpot> $jackpots
 * @property-read int|null $jackpots_count
 * @property-read User|null $owner
 * @property-read Collection<int, User> $staff
 * @property-read int|null $staff_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static Builder<static>|Shop newModelQuery()
 * @method static Builder<static>|Shop newQuery()
 * @method static Builder<static>|Shop query()
 * @method static Builder<static>|Shop visibleTo(?User $viewer)
 * @method static Builder<static>|Shop whereAllowedCountries($value)
 * @method static Builder<static>|Shop whereAllowedDevices($value)
 * @method static Builder<static>|Shop whereAllowedOs($value)
 * @method static Builder<static>|Shop whereBalance($value)
 * @method static Builder<static>|Shop whereCreatedAt($value)
 * @method static Builder<static>|Shop whereCurrency($value)
 * @method static Builder<static>|Shop whereFrontend($value)
 * @method static Builder<static>|Shop whereHappyHoursEnabled($value)
 * @method static Builder<static>|Shop whereId($value)
 * @method static Builder<static>|Shop whereInvitesEnabled($value)
 * @method static Builder<static>|Shop whereMaxWinMultiplier($value)
 * @method static Builder<static>|Shop whereName($value)
 * @method static Builder<static>|Shop whereOrderBy($value)
 * @method static Builder<static>|Shop whereOwnerId($value)
 * @method static Builder<static>|Shop wherePlayerLimit($value)
 * @method static Builder<static>|Shop whereProgressEnabled($value)
 * @method static Builder<static>|Shop whereRequiredRules($value)
 * @method static Builder<static>|Shop whereRtpPercent($value)
 * @method static Builder<static>|Shop whereSlug($value)
 * @method static Builder<static>|Shop whereSmsBonusesEnabled($value)
 * @method static Builder<static>|Shop whereStatus($value)
 * @method static Builder<static>|Shop whereUpdatedAt($value)
 * @method static Builder<static>|Shop whereWelcomeBonusesEnabled($value)
 * @method static Builder<static>|Shop whereWheelFortuneEnabled($value)
 *
 * @mixin \Eloquent
 */
class Shop extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'order_by' => GameOrder::class,
            'currency' => Currency::class,
            'balance' => 'decimal:4',
            'player_limit' => 'decimal:4',
            'allowed_countries' => 'array',
            'allowed_os' => 'array',
            'allowed_devices' => 'array',
            'required_rules' => 'array',
            'happy_hours_enabled' => 'boolean',
            'progress_enabled' => 'boolean',
            'invites_enabled' => 'boolean',
            'welcome_bonuses_enabled' => 'boolean',
            'sms_bonuses_enabled' => 'boolean',
            'wheel_fortune_enabled' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function banks(): HasMany
    {
        return $this->hasMany(GameBank::class);
    }

    public function jackpots(): HasMany
    {
        return $this->hasMany(Jackpot::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if (! $viewer || $viewer->isAdmin()) {
            return $query;
        }

        return $query->whereIn('shops.id', Hierarchy::visibleShopIds($viewer) ?: [0]);
    }

    public function bank(Currency|string|null $currency = null): ?GameBank
    {
        $currency = match (true) {
            $currency instanceof Currency => $currency->value,
            is_string($currency) => $currency,
            default => $this->currency->value,
        };

        /** @var GameBank|null */
        return $this->banks()->where('currency', $currency)->first();
    }

    /**
     * Every distinct currency this shop transacts in (base + bank currencies).
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        return collect([$this->currency->value])
            ->merge($this->banks()->pluck('currency')->map(
                fn ($c) => $c instanceof Currency ? $c->value : (string) $c
            ))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
