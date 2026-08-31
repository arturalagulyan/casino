<?php

namespace App\Filament\Resources\GameTemplates\Tables;

use App\Enums\BankType;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Models\GameTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GameTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('provider')
                    ->searchable(),
                TextColumn::make('engine')
                    ->badge()
                    ->searchable(),
                TextColumn::make('package_path')
                    ->searchable(),
                TextColumn::make('client_path')
                    ->searchable(),
                TextColumn::make('device')
                    ->badge()
                    ->searchable(),
                TextColumn::make('bank_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('default_denomination')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('scale_mode')
                    ->badge()
                    ->searchable(),
                TextColumn::make('view_state')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('engine')
                    ->options(collect(GameEngine::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                SelectFilter::make('device')
                    ->options(collect(Device::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                SelectFilter::make('bank_type')
                    ->options(collect(BankType::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                SelectFilter::make('provider')
                    ->options(fn () => GameTemplate::query()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
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
