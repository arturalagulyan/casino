<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Enums\Currency;
use App\Enums\GameOrder;
use App\Enums\ShopStatus;
use App\Filament\Actions\AdjustShopCreditAction;
use App\Filament\Support\TableFilters;
use App\Models\Shop;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->description(fn ($record) => $record->slug)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ShopStatus $state) => ucfirst($state->value))
                    ->color(fn (ShopStatus $state) => match ($state) {
                        ShopStatus::Active => 'success',
                        ShopStatus::Blocked => 'danger',
                        ShopStatus::Pending => 'warning',
                    }),
                TextColumn::make('currency')
                    ->badge()
                    ->color('gray')
                    ->html()
                    ->formatStateUsing(fn ($state) => Currency::chipFor($state))
                    ->sortable(),
                TextColumn::make('balance')
                    ->label('Credit')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('rtp_percent')
                    ->label('RTP')
                    ->suffix(' %')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('order_by')
                    ->label('Order')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('frontend')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->badge(),
                TextColumn::make('games_count')
                    ->counts('games')
                    ->label('Games')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('owner.username')
                    ->label('Owner')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ShopStatus::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])),
                TableFilters::currency(),
                SelectFilter::make('order_by')
                    ->label('Game order')
                    ->options(collect(GameOrder::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                SelectFilter::make('frontend')
                    ->options(fn () => Shop::query()->distinct()->orderBy('frontend')->pluck('frontend', 'frontend')->all()),
                SelectFilter::make('owner_id')
                    ->label('Owner')
                    ->relationship('owner', 'username')
                    ->searchable(),
                TableFilters::amountRange('balance', 'Credit'),
                TableFilters::amountRange('rtp_percent', 'RTP %'),
            ])
            ->recordActions([
                AdjustShopCreditAction::make(),
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
