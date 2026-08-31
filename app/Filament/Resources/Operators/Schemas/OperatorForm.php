<?php

namespace App\Filament\Resources\Operators\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OperatorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name')
                    ->searchable()
                    ->helperText('Leave empty for a global operator.'),
                TextInput::make('operator_ref')
                    ->label('Operator ID (opid)')
                    ->required(),
                TextInput::make('user_check_url')
                    ->label('User-check URL (ucurl)')
                    ->url()
                    ->helperText('Endpoint we call to read the player / balance.'),
                TextInput::make('callback_url')
                    ->label('Callback URL (cburl)')
                    ->url()
                    ->helperText('Endpoint we call to post balance updates.'),
            ]);
    }
}
