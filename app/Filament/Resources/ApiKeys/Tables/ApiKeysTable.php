<?php

namespace App\Filament\Resources\ApiKeys\Tables;

use App\Filament\Actions\RegenerateApiKeyAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ApiKeysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop.name')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('name')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('key')
                    ->label('Key')
                    ->badge()
                    ->copyable()
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('allowed_ips')
                    ->label('IPs')
                    ->badge()
                    ->separator(',')
                    ->placeholder('any')
                    ->searchable(),
                TextColumn::make('callback_url')
                    ->label('Endpoint')
                    ->limit(40)
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_used_at')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shop')->relationship('shop', 'name'),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                RegenerateApiKeyAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
