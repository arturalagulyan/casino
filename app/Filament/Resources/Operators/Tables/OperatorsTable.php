<?php

namespace App\Filament\Resources\Operators\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OperatorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operator_ref')
                    ->label('Operator ID')
                    ->badge()
                    ->copyable()
                    ->searchable(),
                TextColumn::make('shop.name')
                    ->badge()
                    ->color('gray')
                    ->placeholder('global')
                    ->sortable(),
                TextColumn::make('user_check_url')
                    ->label('ucurl')
                    ->limit(45)
                    ->toggleable(),
                TextColumn::make('callback_url')
                    ->label('cburl')
                    ->limit(45)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shop')->relationship('shop', 'name'),
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
