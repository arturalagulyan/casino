<?php

namespace App\Filament\Resources\GameRounds\Tables;

use App\Filament\Support\TableFilters;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GameRoundsTable
{
    public static function configure(Table $table): Table
    {
        $money = fn (string $field, string $label) => TextColumn::make($field)
            ->label($label)
            ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency))
            ->alignEnd()
            ->sortable();

        return $table
            ->defaultSort('played_at', 'desc')
            ->columns([
                TextColumn::make('played_at')->dateTime('d M H:i:s')->sortable(),
                TextColumn::make('user.username')->label('Player')->searchable()->sortable(),
                TextColumn::make('game.title')
                    ->label('Game')
                    ->description(fn ($record) => $record->game_code)
                    ->searchable(),
                TextColumn::make('currency')->badge()->color('gray')->formatStateUsing(fn ($state) => $state?->value),
                $money('bet', 'Bet'),
                $money('win', 'Win')->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray'),
                TextColumn::make('result')
                    ->label('P/L')
                    ->state(fn ($record) => Money::format((float) $record->win - (float) $record->bet, $record->currency))
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($record) => (float) $record->win - (float) $record->bet >= 0 ? 'success' : 'danger'),
                $money('balance_after', 'Balance')->toggleable(),
                TextColumn::make('shop.name')->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('shop')->relationship('shop', 'name'),
                SelectFilter::make('user')
                    ->relationship('user', 'username')
                    ->searchable(),
                SelectFilter::make('game')
                    ->relationship('game', 'title')
                    ->searchable(),
                TableFilters::currency(),
                TableFilters::dateRange('played_at', 'Played'),
                Filter::make('big_wins')
                    ->label('Wins ≥ 50× bet')
                    ->query(fn (Builder $q) => $q->whereRaw('win >= bet * 50')),
                Filter::make('today')
                    ->query(fn (Builder $q) => $q->whereDate('played_at', today())),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
