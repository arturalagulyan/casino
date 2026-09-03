<?php

namespace App\Filament\Resources\Games\Tables;

use App\Enums\BankType;
use App\Enums\GameLabel;
use App\Filament\Actions\PlayDemoAction;
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

class GamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('template.poster_path')
                    ->label('Poster')
                    ->disk('public')
                    ->height(40)
                    ->extraImgAttributes(['loading' => 'lazy']),
                TextColumn::make('template.title')
                    ->label('Game')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('shop.name')
                    ->badge()
                    ->searchable(),
                TextColumn::make('categories.title')
                    ->label('Categories')
                    ->badge()
                    ->separator(',')
                    ->toggleable(),
                TextColumn::make('jackpot.name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('title')
                    ->label('Per-shop name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('label')
                    ->badge()
                    ->searchable(),
                TextColumn::make('bank_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('reserve_percent')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cask')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('denomination')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('scale_mode')
                    ->badge()
                    ->searchable(),
                TextColumn::make('view_state')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_visible')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_bet')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_win')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rounds_count')
                    ->numeric()
                    ->sortable(),
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
                SelectFilter::make('shop')->relationship('shop', 'name'),
                SelectFilter::make('template')
                    ->relationship('template', 'title')
                    ->searchable(),
                SelectFilter::make('categories')
                    ->relationship('categories', 'title')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('label')
                    ->options(collect(GameLabel::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                SelectFilter::make('bank_type')
                    ->options(collect(BankType::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                TernaryFilter::make('is_visible')->label('Visible'),
                TernaryFilter::make('has_jackpot')
                    ->label('Jackpot attached')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('jackpot_id'),
                        false: fn ($q) => $q->whereNull('jackpot_id'),
                    ),
            ])
            ->recordActions([
                PlayDemoAction::make(),
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
