<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Hierarchy;
use Illuminate\Database\Eloquent\Builder;

/**
 * `Model::visibleTo($staff)` — limits a shop-scoped model to the shops the
 * viewer's place in the hierarchy allows. Admins are unrestricted.
 */
trait ScopedToShopHierarchy
{
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if (! $viewer || $viewer->isAdmin()) {
            return $query;
        }

        $shopIds = Hierarchy::visibleShopIds($viewer) ?: [0];
        $column = $query->getModel()->getTable().'.shop_id';

        // Rows with no shop are global templates (categories, jackpots) — keep them.
        return $query->where(
            fn ($q) => $q->whereIn($column, $shopIds)->orWhereNull($column)
        );
    }
}
