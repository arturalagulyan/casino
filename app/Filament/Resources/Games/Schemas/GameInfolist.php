<?php

namespace App\Filament\Resources\Games\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GameInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('template.poster_path')
                    ->label('Poster')
                    ->disk('public')
                    ->height(120)
                    ->placeholder('no poster'),
                TextEntry::make('shop.name')
                    ->label('Shop'),
                TextEntry::make('template.title')
                    ->label('Template'),
                TextEntry::make('jackpot.name')
                    ->label('Jackpot')
                    ->placeholder('-'),
                TextEntry::make('title')
                    ->placeholder('-'),
                TextEntry::make('label')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('bank_type')
                    ->badge(),
                TextEntry::make('reserve_percent')
                    ->numeric(),
                TextEntry::make('cask')
                    ->numeric(),
                TextEntry::make('denomination')
                    ->numeric(),
                TextEntry::make('scale_mode')
                    ->badge(),
                TextEntry::make('view_state')
                    ->badge(),
                IconEntry::make('is_visible')
                    ->boolean(),
                TextEntry::make('sort_order')
                    ->numeric(),
                TextEntry::make('total_bet')
                    ->numeric(),
                TextEntry::make('total_win')
                    ->numeric(),
                TextEntry::make('rounds_count')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
