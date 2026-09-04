<?php

namespace App\Filament\Resources\Jackpots\Schemas;

use App\Enums\Currency;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class JackpotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name'),
                TextInput::make('name')
                    ->required(),
                Select::make('currency')
                    ->options(Currency::options())
                    ->searchable()
                    ->helperText('Pool home currency. Stakes convert in, payouts convert out. Blank = shop currency.'),
                TextInput::make('balance')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('contribution_percent')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('seed_min')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('seed_max')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('payout_min')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('payout_max')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                Select::make('last_winner_id')
                    ->relationship('lastWinner', 'username')
                    ->searchable(),
                DateTimePicker::make('last_won_at'),
                TextInput::make('last_won_amount')
                    ->numeric(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
