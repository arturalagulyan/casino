<?php

namespace App\Filament\Resources\GameBanks\Schemas;

use App\Enums\Currency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GameBankInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shop.name')
                    ->label('Shop'),
                TextEntry::make('currency')
                    ->badge()
                    ->html()
                    ->formatStateUsing(fn ($state) => Currency::chipFor($state)),
                TextEntry::make('slots')
                    ->numeric(),
                TextEntry::make('little')
                    ->numeric(),
                TextEntry::make('table_bank')
                    ->numeric(),
                TextEntry::make('bonus')
                    ->numeric(),
                TextEntry::make('fish')
                    ->numeric(),
                TextEntry::make('temp_rtp')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
