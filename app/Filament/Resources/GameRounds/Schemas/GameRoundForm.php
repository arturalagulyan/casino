<?php

namespace App\Filament\Resources\GameRounds\Schemas;

use App\Enums\Currency;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GameRoundForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name')
                    ->required(),
                Select::make('user_id')
                    ->relationship('user', 'id')
                    ->required(),
                Select::make('game_id')
                    ->relationship('game', 'title'),
                TextInput::make('game_code')
                    ->required(),
                Select::make('currency')
                    ->options(Currency::options())
                    ->required()
                    ->default(Currency::default()->value),
                TextInput::make('bet')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('win')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('balance_after')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('stake_to_bank')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('stake_to_jackpot')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('stake_to_profit')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('denomination')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('bank_snapshot'),
                TextInput::make('status')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('played_at')
                    ->required(),
            ]);
    }
}
