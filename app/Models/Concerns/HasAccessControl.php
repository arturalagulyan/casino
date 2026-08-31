<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Role / permission helpers backed by the roles, permissions, role_user,
 * permission_role and permission_user tables (legacy jeremykenedy shape).
 *
 * `users.role_id` is the primary role; extra roles live in `role_user`.
 * Effective permissions = permissions of every attached role  ∪  direct grants.
 */
trait HasAccessControl
{
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    /** Every role: the primary (role_id) plus any in role_user. */
    public function allRoles(): Collection
    {
        return $this->roles
            ->when($this->role_id, fn ($roles) => $roles->push($this->role)->filter())
            ->unique('id')
            ->values();
    }

    public function hasRole(string|array $slug): bool
    {
        $slugs = (array) $slug;

        return $this->allRoles()->contains(fn (Role $r) => in_array($r->slug, $slugs, true));
    }

    public function roleLevel(): int
    {
        return (int) $this->allRoles()->max('level');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isPlayer(): bool
    {
        return $this->hasRole('user') && ! $this->hasStaffRole();
    }

    public function hasStaffRole(): bool
    {
        return $this->hasRole(['cashier', 'manager', 'distributor', 'agent', 'admin']);
    }

    /** All permission slugs this user effectively has. */
    public function permissionSlugs(): Collection
    {
        return once(function () {
            if ($this->isAdmin()) {
                return Permission::query()->pluck('slug');
            }

            $fromRoles = $this->allRoles()
                ->loadMissing('permissions')
                ->flatMap(fn (Role $r) => $r->permissions->pluck('slug'));

            return $fromRoles
                ->merge($this->permissions->pluck('slug'))
                ->unique()
                ->values();
        });
    }

    public function hasPermission(string|array $slug): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $slugs = $this->permissionSlugs();

        foreach ((array) $slug as $needle) {
            if ($slugs->contains($needle)) {
                return true;
            }
        }

        return false;
    }

    public function assignRole(Role|string|int $role): void
    {
        $role = $role instanceof Role
            ? $role
            : Role::query()->where('slug', $role)->orWhere('id', $role)->firstOrFail();

        $this->roles()->syncWithoutDetaching([$role->id]);

        if (! $this->role_id) {
            $this->forceFill(['role_id' => $role->id])->save();
        }
    }
}
