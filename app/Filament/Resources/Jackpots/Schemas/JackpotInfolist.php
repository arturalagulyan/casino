<?php

namespace App\Filament\Resources\Jackpots\Schemas;

use App\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class JackpotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shop.name')
                    ->label('Shop')
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('currency')
                    ->label('Pool currency')
                    ->html()
                    ->state(fn ($record) => $record->poolCurrency()->chip())
                    ->badge(),
                TextEntry::make('balance')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->poolCurrency())),
                TextEntry::make('contribution_percent')
                    ->numeric(),
                TextEntry::make('seed_min')
                    ->numeric(),
                TextEntry::make('seed_max')
                    ->numeric(),
                TextEntry::make('payout_min')
                    ->numeric(),
                TextEntry::make('payout_max')
                    ->numeric(),
                TextEntry::make('lastWinner.id')
                    ->label('Last winner')
                    ->placeholder('-'),
                TextEntry::make('last_won_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_won_amount')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
