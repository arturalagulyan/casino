<?php

namespace App\Filament\Resources\Transactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shop.name')
                    ->label('Shop')
                    ->placeholder('-'),
                TextEntry::make('user.id')
                    ->label('User'),
                TextEntry::make('counterparty.id')
                    ->label('Counterparty')
                    ->placeholder('-'),
                TextEntry::make('direction')
                    ->badge(),
                TextEntry::make('source')
                    ->badge(),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('balance_before')
                    ->numeric(),
                TextEntry::make('secondary_amount')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('multiplier')
                    ->numeric(),
                TextEntry::make('reference_type')
                    ->placeholder('-'),
                TextEntry::make('reference_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('title')
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
