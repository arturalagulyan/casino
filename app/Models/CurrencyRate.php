<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Currency $currency
 * @property numeric $rate
 * @property Carbon|null $quoted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|CurrencyRate newModelQuery()
 * @method static Builder<static>|CurrencyRate newQuery()
 * @method static Builder<static>|CurrencyRate query()
 * @method static Builder<static>|CurrencyRate whereCreatedAt($value)
 * @method static Builder<static>|CurrencyRate whereCurrency($value)
 * @method static Builder<static>|CurrencyRate whereId($value)
 * @method static Builder<static>|CurrencyRate whereQuotedAt($value)
 * @method static Builder<static>|CurrencyRate whereRate($value)
 * @method static Builder<static>|CurrencyRate whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CurrencyRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'rate' => 'decimal:10',
            'quoted_at' => 'datetime',
        ];
    }
}
