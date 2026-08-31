<?php

namespace App\Filament\Resources\Permissions\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('group')
            ->groups([
                Group::make('group')->label('Group')->collapsible(),
            ])
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('roles_count')
                    ->counts('roles')
                    ->label('Roles')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
