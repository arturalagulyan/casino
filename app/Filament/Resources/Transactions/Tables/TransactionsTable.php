<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Filament\Support\TableFilters;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('d M H:i:s')->sortable(),
                TextColumn::make('user.username')->label('Account')->searchable()->sortable(),
                TextColumn::make('counterparty.username')->label('By')->toggleable()->placeholder('—'),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (TxnSource $state) => Str::headline($state->value)),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (TxnDirection $state) => ucfirst($state->value))
                    ->color(fn (TxnDirection $state) => $state === TxnDirection::Credit ? 'success' : 'danger'),
                TextColumn::make('currency')->badge()->color('gray')->formatStateUsing(fn ($state) => $state?->value)->toggleable(),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($record) => $record->direction === TxnDirection::Credit ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state, $record) => ($record->direction === TxnDirection::Credit ? '+ ' : '− ').Money::format($state, $record->currency)),
                TextColumn::make('balance_before')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('shop.name')->badge()->color('gray')->toggleable(),
                TextColumn::make('title')->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->options(collect(TxnSource::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
                SelectFilter::make('direction')
                    ->options([TxnDirection::Credit->value => 'Credit', TxnDirection::Debit->value => 'Debit']),
                SelectFilter::make('shop')->relationship('shop', 'name'),
                SelectFilter::make('user')
                    ->relationship('user', 'username')
                    ->searchable(),
                TableFilters::currency(),
                TableFilters::dateRange('created_at', 'Date'),
                Filter::make('today')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', today())),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
