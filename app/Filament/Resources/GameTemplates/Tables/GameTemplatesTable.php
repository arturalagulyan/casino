<?php

namespace App\Filament\Resources\GameTemplates\Tables;

use App\Enums\BankType;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Filament\Actions\PlayDemoAction;
use App\Filament\Actions\UploadGameBundleAction;
use App\Models\Category;
use App\Models\GameTemplate;
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
use Illuminate\Database\Eloquent\Builder;

class GameTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('games.categories'))
            ->columns([
                ImageColumn::make('poster_path')
                    ->label('Poster')
                    ->disk('public')
                    ->height(40)
                    ->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('title')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Asset key')
                    ->tooltip('Internal code that keys the bundle files — keeps its provider suffix')
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('engine')
                    ->badge()
                    ->searchable(),
                TextColumn::make('categories')
                    ->label('Categories')
                    ->badge()
                    ->separator(',')
                    ->state(fn (GameTemplate $record) => $record->categories->pluck('title')->all())
                    ->placeholder('—')
                    ->toggleable(),
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
                SelectFilter::make('category')
                    ->label('Category')
                    ->searchable()
                    ->options(fn () => Category::query()->orderBy('title')->pluck('title', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $id) => $q->whereHas('games.categories', fn (Builder $c) => $c->whereKey($id)),
                    )),
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
