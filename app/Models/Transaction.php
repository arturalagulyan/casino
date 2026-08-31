<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The money ledger — every balance movement for any account.
 * ← w_statistics (+ w_statistics_add folded into `accounting`).
 */
class Transaction extends Model
{
    use ScopedToShopHierarchy;

    public const UPDATED_AT = null;

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
