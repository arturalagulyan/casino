<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shop-wide liquidity pools.  ← w_game_bank + w_fish_bank
 *
 * @property int $id
 * @property int $shop_id
 * @property Currency $currency
 * @property numeric $slots
 * @property numeric $little
 * @property numeric $table_bank
 * @property numeric $bonus
 * @property numeric $fish
 * @property numeric|null $temp_rtp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 *
 * @method static Builder<static>|GameBank newModelQuery()
 * @method static Builder<static>|GameBank newQuery()
 * @method static Builder<static>|GameBank query()
 * @method static Builder<static>|GameBank visibleTo(?User $viewer)
 * @method static Builder<static>|GameBank whereBonus($value)
 * @method static Builder<static>|GameBank whereCreatedAt($value)
 * @method static Builder<static>|GameBank whereCurrency($value)
 * @method static Builder<static>|GameBank whereFish($value)
 * @method static Builder<static>|GameBank whereId($value)
 * @method static Builder<static>|GameBank whereLittle($value)
 * @method static Builder<static>|GameBank whereShopId($value)
 * @method static Builder<static>|GameBank whereSlots($value)
 * @method static Builder<static>|GameBank whereTableBank($value)
 * @method static Builder<static>|GameBank whereTempRtp($value)
 * @method static Builder<static>|GameBank whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class GameBank extends Model
{
    use ScopedToShopHierarchy;

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
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function amountFor(BankType $type): string
    {
        return $this->{$type->column()};
    }

    public function total(): string
    {
        return number_format(
            (float) $this->slots + (float) $this->little + (float) $this->table_bank
            + (float) $this->bonus + (float) $this->fish,
            4, '.', ''
        );
    }
}
