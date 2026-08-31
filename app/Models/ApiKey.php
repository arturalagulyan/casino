<?php

namespace App\Models;

use App\Models\Concerns\ScopedToShopHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Seamless-wallet credentials per shop.  ← w_apis */
class ApiKey extends Model
{
    use ScopedToShopHierarchy;

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
