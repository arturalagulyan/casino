<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\GameOrder;
use App\Enums\ShopStatus;
use App\Support\Hierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        $currency = $currency instanceof Currency
            ? $currency->value
            : ($currency ?? ($this->currency?->value ?? Currency::default()->value));

        return $this->banks()->where('currency', $currency)->first();
    }

    /** Every distinct currency this shop transacts in (base + bank currencies). */
    public function currencies(): array
    {
        return collect([$this->currency?->value])
            ->merge($this->banks()->pluck('currency')->map(
                fn ($c) => $c instanceof Currency ? $c->value : $c
            ))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
