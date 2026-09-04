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
