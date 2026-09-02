<?php

namespace App\Models;

use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Seamless-wallet credentials per shop.  ← w_apis
 *
 * @property int $id
 * @property int $shop_id
 * @property string|null $name
 * @property string $key
 * @property string|null $secret
 * @property array<array-key, mixed>|null $allowed_ips
 * @property string|null $callback_url
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 *
 * @method static Builder<static>|ApiKey newModelQuery()
 * @method static Builder<static>|ApiKey newQuery()
 * @method static Builder<static>|ApiKey query()
 * @method static Builder<static>|ApiKey visibleTo(?User $viewer)
 * @method static Builder<static>|ApiKey whereAllowedIps($value)
 * @method static Builder<static>|ApiKey whereCallbackUrl($value)
 * @method static Builder<static>|ApiKey whereCreatedAt($value)
 * @method static Builder<static>|ApiKey whereId($value)
 * @method static Builder<static>|ApiKey whereIsActive($value)
 * @method static Builder<static>|ApiKey whereKey($value)
 * @method static Builder<static>|ApiKey whereLastUsedAt($value)
 * @method static Builder<static>|ApiKey whereName($value)
 * @method static Builder<static>|ApiKey whereSecret($value)
 * @method static Builder<static>|ApiKey whereShopId($value)
 * @method static Builder<static>|ApiKey whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ApiKey extends Model
{
    use ScopedToShopHierarchy;

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
