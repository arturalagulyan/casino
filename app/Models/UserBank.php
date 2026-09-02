<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RECONSTRUCTED per-player liquidity pool (individual RTP).
 *
 * Legacy w_user_bank was not present in the source DB — see docs/DATABASE.md.
 *
 * @property int $id
 * @property int $user_id
 * @property int $shop_id
 * @property Currency $currency
 * @property numeric $slots
 * @property numeric $little
 * @property numeric $table_bank
 * @property numeric $bonus
 * @property numeric $fish
 * @property numeric|null $temp_rtp
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read User|null $user
 *
 * @method static Builder<static>|UserBank newModelQuery()
 * @method static Builder<static>|UserBank newQuery()
 * @method static Builder<static>|UserBank query()
 * @method static Builder<static>|UserBank whereBonus($value)
 * @method static Builder<static>|UserBank whereCreatedAt($value)
 * @method static Builder<static>|UserBank whereCurrency($value)
 * @method static Builder<static>|UserBank whereFish($value)
 * @method static Builder<static>|UserBank whereId($value)
 * @method static Builder<static>|UserBank whereIsActive($value)
 * @method static Builder<static>|UserBank whereLittle($value)
 * @method static Builder<static>|UserBank whereShopId($value)
 * @method static Builder<static>|UserBank whereSlots($value)
 * @method static Builder<static>|UserBank whereTableBank($value)
 * @method static Builder<static>|UserBank whereTempRtp($value)
 * @method static Builder<static>|UserBank whereUpdatedAt($value)
 * @method static Builder<static>|UserBank whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserBank extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'slots' => 'decimal:4',
            'little' => 'decimal:4',
            'table_bank' => 'decimal:4',
            'bonus' => 'decimal:4',
            'fish' => 'decimal:4',
            'temp_rtp' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function amountFor(BankType $type): string
    {
        return $this->{$type->column()};
    }
}
