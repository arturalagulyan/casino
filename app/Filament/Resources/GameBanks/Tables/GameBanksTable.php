<?php

namespace App\Filament\Resources\GameBanks\Tables;

use App\Filament\Actions\AdjustBankPoolAction;
use App\Filament\Support\TableFilters;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GameBanksTable
{
    public static function configure(Table $table): Table
    {
        $pool = fn (string $field, string $label) => TextColumn::make($field)
            ->label($label)
            ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency))
            ->alignEnd()
            ->sortable();

        return $table
            ->columns([
                TextColumn::make('shop.name')->weight('bold')->searchable()->sortable(),
                TextColumn::make('currency')->badge()->color('gray')->formatStateUsing(fn ($state) => $state?->value),
                $pool('slots', 'Slots'),
                $pool('little', 'Little'),
                $pool('table_bank', 'Table'),
                $pool('bonus', 'Bonus'),
                $pool('fish', 'Fish'),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn ($record) => Money::format($record->total(), $record->currency))
                    ->weight('bold')
                    ->color('success')
                    ->alignEnd(),
                TextColumn::make('temp_rtp')
                    ->label('Manual RTP')
                    ->placeholder('auto')
                    ->badge()
                    ->color('warning'),
            ])
            ->filters([
                SelectFilter::make('shop')->relationship('shop', 'name'),
                TableFilters::currency(),
            ])
            ->recordActions([
                AdjustBankPoolAction::make(),
                EditAction::make(),
            ]);
    }
}
