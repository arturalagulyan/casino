<?php

namespace App\Filament\Concerns;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the model's `visibleTo` scope (App\Models\Concerns\ScopedToShopHierarchy
 * / User::scopeVisibleTo) to a resource's base query, so an agent / distributor /
 * shop staffer only ever sees their slice of the tree. Admins are unrestricted.
 *
 * On top of that, narrows to the shop picked in the topbar switcher (App\Support\CurrentShop),
 * if any — this can only ever narrow further, never widen, what `visibleTo` already allowed.
 */
trait ScopesToViewer
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && method_exists(static::getModel(), 'scopeVisibleTo')) {
            /** @phpstan-ignore method.notFound (scope resolved dynamically, guarded by method_exists) */
            $query->visibleTo($user);
        }

        static::scopeToCurrentShop($query);

        return $query;
    }

    protected static function scopeToCurrentShop(Builder $query): void
    {
        $shopId = CurrentShop::id();

        if (! $shopId) {
            return;
        }

        $model = static::getModel();
        $table = $query->getModel()->getTable();

        if ($model === Shop::class) {
            $query->where("{$table}.id", $shopId);

            return;
        }

        if ($model === User::class) {
            $query->where(
                fn (Builder $q) => $q->where('users.shop_id', $shopId)
                    ->orWhereHas('shops', fn (Builder $sq) => $sq->whereKey($shopId))
            );

            return;
        }

        // Rows with no shop are global templates (categories, jackpots) — keep them.
        $query->where(
            fn (Builder $q) => $q->where("{$table}.shop_id", $shopId)->orWhereNull("{$table}.shop_id")
        );
    }
}
