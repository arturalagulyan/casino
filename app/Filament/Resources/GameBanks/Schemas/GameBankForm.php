<?php

namespace App\Filament\Resources\GameBanks\Schemas;

use App\Enums\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GameBankForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name')
                    ->required(),
                Select::make('currency')
                    ->options(Currency::options())
                    ->searchable()
                    ->required()
                    ->default(Currency::default()->value),
                TextInput::make('slots')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('little')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('table_bank')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('bonus')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('fish')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('temp_rtp')
                    ->numeric(),
            ]);
    }
}
