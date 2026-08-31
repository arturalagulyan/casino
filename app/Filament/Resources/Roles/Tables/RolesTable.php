<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('level', 'desc')
            ->columns([
                TextColumn::make('level')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('name')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions')
                    ->badge()
                    ->color('success'),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->badge(),
                TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
