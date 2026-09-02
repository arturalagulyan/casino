<?php

namespace App\Filament\Resources\GameTemplates\Tables;

use App\Enums\BankType;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Filament\Actions\PlayDemoAction;
use App\Filament\Actions\UploadGameBundleAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
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
                ImageColumn::make('poster_path')
                    ->label('Poster')
                    ->disk('public')
                    ->height(40)
                    ->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('code')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('engine')
                    ->badge()
                    ->searchable(),
                TextColumn::make('bank_type')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('device')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('front_end')
                    ->label('Front-end')
                    ->badge()
                    ->state(fn ($record) => $record->activeBundle?->version
                        ? "v{$record->activeBundle->version}"
                        : 'not uploaded')
                    ->color(fn ($state) => str_starts_with((string) $state, 'v') ? 'success' : 'danger'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
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
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('has_bundle')
                    ->label('Front-end uploaded')
                    ->queries(
                        true: fn ($q) => $q->whereHas('bundles', fn ($b) => $b->where('is_active', true)),
                        false: fn ($q) => $q->whereDoesntHave('bundles', fn ($b) => $b->where('is_active', true)),
                    ),
            ])
            ->recordActions([
                PlayDemoAction::make(),
                UploadGameBundleAction::make(),
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
