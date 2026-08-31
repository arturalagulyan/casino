<?php

namespace App\Filament\Resources\Permissions\Schemas;

use App\Models\Permission;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('Referenced by code and Gate checks — change with care.'),
                TextInput::make('group')
                    ->datalist(fn () => Permission::query()->distinct()->pluck('group')->filter()->all()),
                TextInput::make('sort')->numeric()->default(0),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]);
    }
}
