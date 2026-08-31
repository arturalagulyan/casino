<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Enums\Currency;
use App\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ShopInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('frontend'),
                TextEntry::make('currency')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof Currency ? $state->label() : $state),
                TextEntry::make('balance')
                    ->label('Shop credit')
                    ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency)),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('rtp_percent')
                    ->numeric(),
                TextEntry::make('max_win_multiplier')
                    ->numeric(),
                TextEntry::make('player_limit')
                    ->numeric(),
                TextEntry::make('order_by')
                    ->badge(),
                TextEntry::make('owner.id')
                    ->label('Owner')
                    ->placeholder('-'),
                IconEntry::make('happy_hours_enabled')
                    ->boolean(),
                IconEntry::make('progress_enabled')
                    ->boolean(),
                IconEntry::make('invites_enabled')
                    ->boolean(),
                IconEntry::make('welcome_bonuses_enabled')
                    ->boolean(),
                IconEntry::make('sms_bonuses_enabled')
                    ->boolean(),
                IconEntry::make('wheel_fortune_enabled')
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
