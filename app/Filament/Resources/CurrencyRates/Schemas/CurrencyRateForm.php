<?php

namespace App\Filament\Resources\CurrencyRates\Schemas;

use App\Enums\Currency;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CurrencyRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('currency')
                ->options(Currency::options())
                ->required()
                ->searchable()
                ->unique(ignoreRecord: true),
            TextInput::make('rate')
                ->label('Units per 1 EUR')
                ->numeric()
                ->required()
                ->minValue(0)
                ->helperText('e.g. USD ≈ 1.08, ALL ≈ 99. EUR is always 1.'),
            DateTimePicker::make('quoted_at')
                ->label('Quoted at')
                ->default(now()),
        ]);
    }
}
