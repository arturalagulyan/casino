<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;

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
