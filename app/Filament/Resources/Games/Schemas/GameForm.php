<?php

namespace App\Filament\Resources\Games\Schemas;

use App\Enums\BankType;
use App\Enums\GameLabel;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name')
                    ->required(),
                Select::make('template_id')
                    ->relationship('template', 'title')
                    ->required(),
                Select::make('jackpot_id')
                    ->relationship('jackpot', 'name'),
                TextInput::make('title'),
                Select::make('label')
                    ->options(GameLabel::class),
                Select::make('bank_type')
                    ->options(BankType::class)
                    ->default('slots')
                    ->required(),
                TextInput::make('reserve_percent')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('cask')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('lines_config_spin'),
                TextInput::make('lines_config_spin_bonus'),
                TextInput::make('lines_config_bonus'),
                TextInput::make('lines_config_bonus_bonus'),
                TextInput::make('jackpot_chances'),
                TextInput::make('advanced'),
                TextInput::make('bet_options'),
                TextInput::make('denomination')
                    ->required()
                    ->numeric()
                    ->default(1.0),
                Select::make('scale_mode')
                    ->options(ScaleMode::class)
                    ->required(),
                Select::make('view_state')
                    ->options(ViewState::class)
                    ->required(),
                Toggle::make('is_visible')
                    ->required(),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_bet')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('total_win')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('rounds_count')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
