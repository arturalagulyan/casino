<?php

namespace App\Models;

use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * External operator endpoints for seamless-wallet integration.  ← w_operators
 *
 * @property int $id
 * @property int|null $shop_id
 * @property string|null $operator_ref
 * @property string|null $user_check_url
 * @property string|null $callback_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop|null $shop
 *
 * @method static Builder<static>|Operator newModelQuery()
 * @method static Builder<static>|Operator newQuery()
 * @method static Builder<static>|Operator query()
 * @method static Builder<static>|Operator visibleTo(?User $viewer)
 * @method static Builder<static>|Operator whereCallbackUrl($value)
 * @method static Builder<static>|Operator whereCreatedAt($value)
 * @method static Builder<static>|Operator whereId($value)
 * @method static Builder<static>|Operator whereOperatorRef($value)
 * @method static Builder<static>|Operator whereShopId($value)
 * @method static Builder<static>|Operator whereUpdatedAt($value)
 * @method static Builder<static>|Operator whereUserCheckUrl($value)
 *
 * @mixin \Eloquent
 */
class Operator extends Model
{
    use ScopedToShopHierarchy;

    protected $guarded = ['id'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
