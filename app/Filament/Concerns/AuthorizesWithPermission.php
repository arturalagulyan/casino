<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Gates a Filament resource on one permission slug from the permissions table
 * (see App\Models\Concerns\HasAccessControl). Set:
 *
 *   protected static ?string $permission = 'shops.manage';
 *   protected static bool $readOnly = false;   // true => hide create/edit/delete
 */
trait AuthorizesWithPermission
{
    public static function canViewAny(): bool
    {
        return static::permitted();
    }

    public static function canView(Model $record): bool
    {
        return static::permitted();
    }

    public static function canCreate(): bool
    {
        return ! static::isReadOnly() && static::permitted();
    }

    public static function canEdit(Model $record): bool
    {
        return ! static::isReadOnly() && static::permitted();
    }

    public static function canDelete(Model $record): bool
    {
        return ! static::isReadOnly() && static::permitted();
    }

    public static function canDeleteAny(): bool
    {
        return ! static::isReadOnly() && static::permitted();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::canDelete($record) && auth()->user()?->isAdmin();
    }

    public static function canRestore(Model $record): bool
    {
        return static::canEdit($record);
    }

    protected static function isReadOnly(): bool
    {
        return property_exists(static::class, 'readOnly') && static::$readOnly === true;
    }

    protected static function permitted(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $permission = property_exists(static::class, 'permission') ? static::$permission : null;

        return $permission ? $user->hasPermission($permission) : $user->isAdmin();
    }
}
