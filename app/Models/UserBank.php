<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RECONSTRUCTED per-player liquidity pool (individual RTP).
 * Legacy w_user_bank was not present in the source DB — see docs/DATABASE.md.
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
