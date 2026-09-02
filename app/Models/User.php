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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $shop_id
 * @property int|null $role_id
 * @property int|null $parent_id
 * @property int|null $inviter_id
 * @property string|null $username
 * @property string|null $email
 * @property string $password
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $birthday
 * @property string|null $avatar
 * @property string $language
 * @property Currency|null $currency
 * @property int $rating
 * @property UserStatus $status
 * @property bool $is_blocked
 * @property bool $is_demo_agent
 * @property bool $free_demo
 * @property Carbon|null $agreed_at
 * @property string|null $external_provider
 * @property string|null $external_player_id
 * @property string|null $external_token
 * @property string|null $two_factor_secret
 * @property bool $two_factor_enabled
 * @property string|null $current_session_id
 * @property string|null $confirmation_token
 * @property string|null $sms_token
 * @property Carbon|null $sms_token_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_online_at
 * @property Carbon|null $last_bet_at
 * @property Carbon|null $last_progress_at
 * @property Carbon|null $last_daily_entry_at
 * @property Carbon|null $last_wheel_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read UserBank|null $bank
 * @property-read Collection<int, User> $children
 * @property-read int|null $children_count
 * @property-read Collection<int, GameSession> $gameSessions
 * @property-read int|null $game_sessions_count
 * @property-read Collection<int, User> $invitees
 * @property-read int|null $invitees_count
 * @property-read User|null $inviter
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read User|null $parent
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Role|null $role
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, GameRound> $rounds
 * @property-read int|null $rounds_count
 * @property-read Shop|null $shop
 * @property-read Collection<int, Shop> $shops
 * @property-read int|null $shops_count
 * @property-read Collection<int, Transaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read Wallet|null $wallet
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User onlyTrashed()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User visibleTo(?self $viewer)
 * @method static Builder<static>|User whereAgreedAt($value)
 * @method static Builder<static>|User whereAvatar($value)
 * @method static Builder<static>|User whereBirthday($value)
 * @method static Builder<static>|User whereConfirmationToken($value)
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereCurrency($value)
 * @method static Builder<static>|User whereCurrentSessionId($value)
 * @method static Builder<static>|User whereDeletedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereExternalPlayerId($value)
 * @method static Builder<static>|User whereExternalProvider($value)
 * @method static Builder<static>|User whereExternalToken($value)
 * @method static Builder<static>|User whereFirstName($value)
 * @method static Builder<static>|User whereFreeDemo($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereInviterId($value)
 * @method static Builder<static>|User whereIsBlocked($value)
 * @method static Builder<static>|User whereIsDemoAgent($value)
 * @method static Builder<static>|User whereLanguage($value)
 * @method static Builder<static>|User whereLastBetAt($value)
 * @method static Builder<static>|User whereLastDailyEntryAt($value)
 * @method static Builder<static>|User whereLastLoginAt($value)
 * @method static Builder<static>|User whereLastName($value)
 * @method static Builder<static>|User whereLastOnlineAt($value)
 * @method static Builder<static>|User whereLastProgressAt($value)
 * @method static Builder<static>|User whereLastWheelAt($value)
 * @method static Builder<static>|User whereParentId($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User wherePhone($value)
 * @method static Builder<static>|User wherePhoneVerifiedAt($value)
 * @method static Builder<static>|User whereRating($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereRoleId($value)
 * @method static Builder<static>|User whereShopId($value)
 * @method static Builder<static>|User whereSmsToken($value)
 * @method static Builder<static>|User whereSmsTokenAt($value)
 * @method static Builder<static>|User whereStatus($value)
 * @method static Builder<static>|User whereTwoFactorEnabled($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 * @method static Builder<static>|User whereUsername($value)
 * @method static Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|User withoutTrashed()
 *
 * @mixin \Eloquent
 */
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
