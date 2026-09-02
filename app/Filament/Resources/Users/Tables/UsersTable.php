<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserStatus;
use App\Filament\Actions\AdjustBalanceAction;
use App\Filament\Support\TableFilters;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('username')
                    ->weight('bold')
                    ->description(fn ($record) => $record->email)
                    ->searchable(['username', 'email', 'first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('role.name')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('shop.name')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('currency')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => $state?->value)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('wallet.balance')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state, $record) => $state === null
                        ? '—'
                        : Money::format($state, $record->wallet->currency))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state) => ucfirst($state->value))
                    ->color(fn (UserStatus $state) => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Banned => 'danger',
                        UserStatus::Unconfirmed => 'warning',
                        UserStatus::Inactive => 'gray',
                    }),
                IconColumn::make('is_blocked')
                    ->label('Blocked')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('danger')
                    ->falseColor('gray'),
                TextColumn::make('last_online_at')
                    ->label('Last seen')
                    ->since()
                    ->toggleable()
                    ->placeholder('never'),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->relationship('role', 'name')
                    ->multiple(),
                SelectFilter::make('shop')
                    ->relationship('shop', 'name'),
                SelectFilter::make('status')
                    ->options(collect(UserStatus::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                TableFilters::currency(),
                TernaryFilter::make('is_blocked')->label('Blocked'),
                TernaryFilter::make('online')
                    ->label('Online now')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('gameSessions', fn (Builder $s) => $s->where('is_active', true)),
                        false: fn (Builder $q) => $q->whereDoesntHave('gameSessions', fn (Builder $s) => $s->where('is_active', true)),
                    ),
                Filter::make('has_parent')
                    ->label('Has upline')
                    ->query(fn (Builder $q) => $q->whereNotNull('parent_id')),
                TableFilters::amountRange('wallet.balance', 'Balance')
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] !== null && $data['from'] !== '',
                            fn (Builder $q) => $q->whereHas('wallet', fn (Builder $w) => $w->where('balance', '>=', $data['from'])))
                        ->when($data['to'] !== null && $data['to'] !== '',
                            fn (Builder $q) => $q->whereHas('wallet', fn (Builder $w) => $w->where('balance', '<=', $data['to'])))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                AdjustBalanceAction::make(),
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
