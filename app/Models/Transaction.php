<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The money ledger — every balance movement for any account.
 *
 * ← w_statistics (+ w_statistics_add folded into `accounting`).
 *
 * @property int $id
 * @property int|null $shop_id
 * @property int $user_id
 * @property int|null $counterparty_id
 * @property TxnDirection $direction
 * @property TxnSource $source
 * @property Currency $currency
 * @property numeric $amount
 * @property numeric $balance_before
 * @property numeric|null $secondary_amount
 * @property int $multiplier
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $title
 * @property int $status
 * @property array<array-key, mixed>|null $context
 * @property array<array-key, mixed>|null $accounting
 * @property Carbon $created_at
 * @property string|null $updated_at
 * @property-read User|null $counterparty
 * @property-read Model|\Eloquent|null $reference
 * @property-read Shop|null $shop
 * @property-read User|null $user
 *
 * @method static Builder<static>|Transaction newModelQuery()
 * @method static Builder<static>|Transaction newQuery()
 * @method static Builder<static>|Transaction query()
 * @method static Builder<static>|Transaction visibleTo(?User $viewer)
 * @method static Builder<static>|Transaction whereAccounting($value)
 * @method static Builder<static>|Transaction whereAmount($value)
 * @method static Builder<static>|Transaction whereBalanceBefore($value)
 * @method static Builder<static>|Transaction whereContext($value)
 * @method static Builder<static>|Transaction whereCounterpartyId($value)
 * @method static Builder<static>|Transaction whereCreatedAt($value)
 * @method static Builder<static>|Transaction whereCurrency($value)
 * @method static Builder<static>|Transaction whereDirection($value)
 * @method static Builder<static>|Transaction whereId($value)
 * @method static Builder<static>|Transaction whereMultiplier($value)
 * @method static Builder<static>|Transaction whereReferenceId($value)
 * @method static Builder<static>|Transaction whereReferenceType($value)
 * @method static Builder<static>|Transaction whereSecondaryAmount($value)
 * @method static Builder<static>|Transaction whereShopId($value)
 * @method static Builder<static>|Transaction whereSource($value)
 * @method static Builder<static>|Transaction whereStatus($value)
 * @method static Builder<static>|Transaction whereTitle($value)
 * @method static Builder<static>|Transaction whereUpdatedAt($value)
 * @method static Builder<static>|Transaction whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Transaction extends Model
{
    use ScopedToShopHierarchy;

    public const ?string UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => TxnDirection::class,
            'source' => TxnSource::class,
            'currency' => Currency::class,
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'secondary_amount' => 'decimal:4',
            'context' => 'array',
            'accounting' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
