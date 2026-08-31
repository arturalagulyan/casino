<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name'),
                Select::make('user_id')
                    ->relationship('user', 'id')
                    ->required(),
                Select::make('counterparty_id')
                    ->relationship('counterparty', 'id'),
                Select::make('direction')
                    ->options(TxnDirection::class)
                    ->required(),
                Select::make('source')
                    ->options(TxnSource::class)
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('balance_before')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('secondary_amount')
                    ->numeric(),
                TextInput::make('multiplier')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('reference_type'),
                TextInput::make('reference_id')
                    ->numeric(),
                TextInput::make('title'),
                TextInput::make('status')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('context'),
                TextInput::make('accounting'),
            ]);
    }
}
