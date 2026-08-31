<?php

namespace App\Filament\Resources\Jackpots\Tables;

use App\Filament\Actions\JackpotActions;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class JackpotsTable
{
    public static function configure(Table $table): Table
    {
        $shopCurrency = fn ($record) => $record->shop?->currency;

        return $table
            ->columns([
                TextColumn::make('shop.name')
                    ->badge()
                    ->color('gray')
                    ->placeholder('global')
                    ->sortable(),
                TextColumn::make('name')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('balance')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $shopCurrency($record)))
                    ->weight('bold')
                    ->color('success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('contribution_percent')
                    ->label('Accrual')
                    ->suffix(' %')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('seed_range')
                    ->label('Seed')
                    ->state(fn ($record) => Money::format($record->seed_min, $shopCurrency($record)).' – '.Money::format($record->seed_max, $shopCurrency($record)))
                    ->toggleable(),
                TextColumn::make('payout_range')
                    ->label('Pays at')
                    ->state(fn ($record) => Money::format($record->payout_min, $shopCurrency($record)).' – '.Money::format($record->payout_max, $shopCurrency($record)))
                    ->toggleable(),
                TextColumn::make('lastWinner.username')
                    ->label('Last winner')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('last_won_at')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('shop')->relationship('shop', 'name'),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                JackpotActions::payout(),
                JackpotActions::setBalance(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                JackpotActions::bulkEdit(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
