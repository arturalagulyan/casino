<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "What can this staff member see?" — the 6-tier tree (user < cashier < manager
 * < distributor < agent < admin) from legacy User::availableUsers() /
 * availableShops(). Admin sees everything; everyone else sees their own
 * parent_id subtree, and shop-bound staff are further limited to their shops.
 */
class Hierarchy
{
    /** User ids in this viewer's subtree (themselves + everyone below via parent_id). */
    public static function descendantIds(User $viewer): array
    {
        $rows = DB::select(
            <<<'SQL'
            WITH RECURSIVE tree AS (
                SELECT id FROM users WHERE id = ?
                UNION ALL
                SELECT u.id FROM users u INNER JOIN tree t ON u.parent_id = t.id
            )
            SELECT id FROM tree
            SQL,
            [$viewer->getKey()],
        );

        return array_map(static fn ($r) => (int) $r->id, $rows);
    }

    /** null = unrestricted (admin). Otherwise the shop ids this viewer may touch. */
    public static function visibleShopIds(User $viewer): ?array
    {
        if ($viewer->isAdmin()) {
            return null;
        }

        $downline = self::descendantIds($viewer);

        $owned = Shop::whereIn('owner_id', $downline)->pluck('id');
        $operated = DB::table('shop_user')->whereIn('user_id', $downline)->pluck('shop_id');
        $own = $viewer->shop_id ? [$viewer->shop_id] : [];

        return $owned->merge($operated)->merge($own)->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** null = unrestricted (admin). Otherwise the user ids this viewer may see. */
    public static function visibleUserIds(User $viewer): ?array
    {
        if ($viewer->isAdmin()) {
            return null;
        }

        $ids = self::descendantIds($viewer);

        // Shop-bound staff also see every player in their shop(s).
        $shopIds = self::visibleShopIds($viewer) ?? [];

        if ($shopIds) {
            $ids = array_merge($ids, User::whereIn('shop_id', $shopIds)->pluck('id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
