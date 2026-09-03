<?php

namespace App\Filament\Resources\CurrencyRates\Tables;

use App\Enums\Currency;
use App\Services\Fx;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CurrencyRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('currency')
                    ->badge()
                    ->color('gray')
                    ->html()
                    ->formatStateUsing(fn ($state) => Currency::chipFor($state))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('currency_name')
                    ->label('Name')
                    ->state(fn ($record) => $record->currency?->currencyName())
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('rate')
                    ->label('Units per €1')
                    ->numeric(decimalPlaces: 6)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('quoted_at')
                    ->label('Quoted')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('currency')
            ->recordActions([
                EditAction::make()->after(fn () => app(Fx::class)->flush()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
