<?php

namespace App\Filament\Resources\GameTemplates\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GameTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('poster_path')
                    ->label('Poster')
                    ->disk('public')
                    ->height(120)
                    ->placeholder('no poster'),
                TextEntry::make('code'),
                TextEntry::make('title'),
                TextEntry::make('engine')
                    ->badge(),
                TextEntry::make('package_path')
                    ->placeholder('-'),
                TextEntry::make('client_path')
                    ->placeholder('-'),
                TextEntry::make('device')
                    ->badge(),
                TextEntry::make('bank_type')
                    ->badge(),
                TextEntry::make('default_denomination')
                    ->numeric(),
                TextEntry::make('scale_mode')
                    ->badge(),
                TextEntry::make('view_state')
                    ->badge(),
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
