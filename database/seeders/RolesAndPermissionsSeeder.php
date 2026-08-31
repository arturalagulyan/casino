<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Roles + permissions carried over from the legacy w_roles / w_permissions.
 * The 6-tier hierarchy (level) is what the staff scoping logic keys off.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** slug => [name, level] */
    private const ROLES = [
        'user' => ['User', 1],
        'cashier' => ['Cashier', 2],
        'manager' => ['Manager', 3],
        'distributor' => ['Distributor', 4],
        'agent' => ['Agent', 5],
        'admin' => ['Admin', 6],
    ];

    /** group => [permission slugs] (from the live w_permissions table) */
    private const PERMISSION_GROUPS = [
        'panel' => ['access.admin.panel', 'dashboard'],
        'users' => ['users.manage', 'users.add', 'users.edit', 'users.delete', 'users.activity', 'users.tree'],
        'games' => ['games.manage', 'games.enable', 'games.disable', 'games.show_count', 'games.rtp'],
        'jackpots' => ['jpgame.manage', 'jpgame.edit'],
        'shops' => [
            'shops.manage', 'shops.add', 'shops.title', 'shops.frontend', 'shops.currency',
            'shops.max_win', 'shops.access', 'shops.os', 'shops.country', 'shops.device',
            'shops.percent', 'shops.order', 'shops.privacy_policy', 'shops.why_bitcoin',
            'shops.terms_and_conditions', 'shops.general_bonus_policy', 'shops.responsible_gaming',
            'shops.delete', 'shops.hard_delete', 'shops.block', 'shops.unblock', 'shops.free_demo',
        ],
        'api' => ['api.manage', 'api.add', 'api.edit', 'api.delete'],
        'stats' => ['stats.pay', 'stats.game', 'stats.shift'],
        'pincodes' => ['pincodes.manage', 'pincodes.add', 'pincodes.edit', 'pincodes.delete'],
        'bonuses' => [
            'happyhours.manage', 'happyhours.add', 'happyhours.edit', 'happyhours.delete',
            'invite.manage', 'invite.edit', 'progress.manage', 'progress.edit',
            'welcome_bonuses.manage', 'welcome_bonuses.edit',
            'sms_bonuses.manage', 'sms_bonuses.add', 'sms_bonuses.edit', 'sms_bonuses.delete',
            'wheelfortune.manage',
        ],
        'tickets' => ['tickets.manage', 'tickets.add'],
        'tournaments' => ['tournaments.manage', 'tournaments.add', 'tournaments.edit', 'tournaments.delete'],
        'activity' => ['activity.system', 'activity.user'],
    ];

    /** role slug => groups it receives ('*' = every group) */
    private const ROLE_GROUPS = [
        'user' => [],
        'cashier' => ['panel', 'users', 'stats', 'pincodes', 'tickets'],
        'manager' => ['panel', 'users', 'stats', 'pincodes', 'tickets', 'bonuses', 'tournaments', 'games', 'jackpots'],
        'distributor' => ['*'],
        'agent' => ['*'],
        'admin' => ['*'],
    ];

    public function run(): void
    {
        $roles = [];
        foreach (self::ROLES as $slug => [$name, $level]) {
            $roles[$slug] = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'level' => $level],
            );
        }

        $permissions = [];
        foreach (self::PERMISSION_GROUPS as $group => $slugs) {
            foreach ($slugs as $slug) {
                $permissions[$slug] = Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => Str::headline(str_replace('.', ' ', $slug)), 'group' => $group],
                );
            }
        }

        foreach (self::ROLE_GROUPS as $roleSlug => $groups) {
            $ids = [];
            $wanted = $groups === ['*'] ? array_keys(self::PERMISSION_GROUPS) : $groups;

            foreach ($wanted as $group) {
                foreach (self::PERMISSION_GROUPS[$group] as $slug) {
                    $ids[] = $permissions[$slug]->id;
                }
            }

            $roles[$roleSlug]->permissions()->sync($ids);
        }
    }
}
