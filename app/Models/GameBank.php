<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shop-wide liquidity pools.  ← w_game_bank + w_fish_bank */
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
