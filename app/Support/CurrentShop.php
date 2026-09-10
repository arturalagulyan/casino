<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shop an admin has picked from the topbar switcher, kept in their
 * session. When set, every shop-scoped Filament resource (via
 * ScopedToShopHierarchy / Shop::visibleTo / User::visibleTo) narrows its
 * query to that shop on top of whatever the hierarchy already allows —
 * it can only ever narrow, never widen, access.
 */
class CurrentShop
{
    private const SESSION_KEY = 'admin.current_shop_id';

    public static function id(): ?int
    {
        $id = session(self::SESSION_KEY);

        return $id ? (int) $id : null;
    }

    public static function set(?int $shopId): void
    {
        if ($shopId === null) {
            session()->forget(self::SESSION_KEY);

            return;
        }

        session([self::SESSION_KEY => $shopId]);
    }

    /**
     * Narrow a hierarchy-derived shop-id list to the switcher's pick, if any.
     * Mirrors ScopesToViewer::scopeToCurrentShop for the report pages / widgets
     * that build raw queries instead of going through a Filament resource.
     *
     * @param  array<int>|null  $shopIds  null = unrestricted (admin)
     * @return array<int>|null
     */
    public static function narrow(?array $shopIds): ?array
    {
        $id = self::id();

        if (! $id) {
            return $shopIds;
        }

        if ($shopIds === null) {
            return [$id];
        }

        // Can only ever narrow: ignore a pick outside what the viewer may see.
        return in_array($id, $shopIds, true) ? [$id] : $shopIds;
    }

    /** Shops the current viewer is allowed to switch into. */
    public static function options(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        return Shop::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']);
    }
}
