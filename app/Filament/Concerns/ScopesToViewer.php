<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the model's `visibleTo` scope (App\Models\Concerns\ScopedToShopHierarchy
 * / User::scopeVisibleTo) to a resource's base query, so an agent / distributor /
 * shop staffer only ever sees their slice of the tree. Admins are unrestricted.
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

        return $query;
    }
}
