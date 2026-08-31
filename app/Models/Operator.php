<?php

namespace App\Models;

use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** External operator endpoints for seamless-wallet integration.  ← w_operators */
class Operator extends Model
{
    use ScopedToShopHierarchy;

    protected $guarded = ['id'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
