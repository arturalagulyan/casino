<?php

namespace App\Filament\Resources\GameRounds\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GameRoundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shop.name')
                    ->label('Shop'),
                TextEntry::make('user.id')
                    ->label('User'),
                TextEntry::make('game.title')
                    ->label('Game')
                    ->placeholder('-'),
                TextEntry::make('game_code'),
                TextEntry::make('currency'),
                TextEntry::make('bet')
                    ->numeric(),
                TextEntry::make('win')
                    ->numeric(),
                TextEntry::make('balance_after')
                    ->numeric(),
                TextEntry::make('stake_to_bank')
                    ->numeric(),
                TextEntry::make('stake_to_jackpot')
                    ->numeric(),
                TextEntry::make('stake_to_profit')
                    ->numeric(),
                TextEntry::make('denomination')
                    ->numeric(),
                TextEntry::make('status')
                    ->numeric(),
                TextEntry::make('played_at')
                    ->dateTime(),
            ]);
    }
}
