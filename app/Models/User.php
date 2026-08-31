<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Models\Concerns\HasAccessControl;
use App\Support\Hierarchy;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasAccessControl;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'external_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'currency' => Currency::class,
            'birthday' => 'date',
            'phone_verified_at' => 'datetime',
            'agreed_at' => 'datetime',
            'sms_token_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_online_at' => 'datetime',
            'last_bet_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'last_daily_entry_at' => 'datetime',
            'last_wheel_at' => 'datetime',
            'is_blocked' => 'boolean',
            'is_demo_agent' => 'boolean',
            'free_demo' => 'boolean',
            'two_factor_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every player/staff row owns exactly one wallet (legacy kept the money
        // columns inline on w_users). Keep its currency in step with the user's.
        static::created(function (User $user): void {
            $user->wallet()->firstOrCreate([], [
                'currency' => $user->currency
                    ?? $user->shop?->currency
                    ?? Currency::default(),
            ]);
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('currency') && $user->currency !== null) {
                $user->wallet()->update(['currency' => $user->currency]);
            }
        });
    }

    /** Limit a query to the users this viewer's hierarchy level can see. */
    public function scopeVisibleTo(Builder $query, ?self $viewer): Builder
    {
        if (! $viewer || $viewer->isAdmin()) {
            return $query;
        }

        return $query->whereIn('users.id', Hierarchy::visibleUserIds($viewer) ?: [0]);
    }

    // ---- Filament -------------------------------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->is_blocked
            && $this->deleted_at === null
            && $this->hasPermission('access.admin.panel');
    }

    public function getFilamentName(): string
    {
        return $this->username ?: trim("{$this->first_name} {$this->last_name}") ?: $this->email ?: "User #{$this->id}";
    }

    // ---- Relationships -------------------------------------------------
    // (role / roles / permissions come from HasAccessControl)

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function bank(): HasOne
    {
        return $this->hasOne(UserBank::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitees(): HasMany
    {
        return $this->hasMany(User::class, 'inviter_id');
    }

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class)->withTimestamps();
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(GameRound::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function gameSessions(): HasMany
    {
        return $this->hasMany(GameSession::class);
    }
}
