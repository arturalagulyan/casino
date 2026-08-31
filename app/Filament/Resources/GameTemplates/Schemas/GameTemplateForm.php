<?php

namespace App\Filament\Resources\GameTemplates\Schemas;

use App\Enums\BankType;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GameTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('provider'),
                Select::make('engine')
                    ->options(GameEngine::class)
                    ->default('internal')
                    ->required(),
                TextInput::make('package_path'),
                TextInput::make('client_path'),
                Select::make('device')
                    ->options(Device::class)
                    ->default('both')
                    ->required(),
                Select::make('bank_type')
                    ->options(BankType::class)
                    ->default('slots')
                    ->required(),
                TextInput::make('default_bet_options'),
                TextInput::make('default_denomination')
                    ->required()
                    ->numeric()
                    ->default(1.0),
                TextInput::make('default_lines_config'),
                TextInput::make('default_jackpot_chances'),
                TextInput::make('default_advanced'),
                Select::make('scale_mode')
                    ->options(ScaleMode::class)
                    ->required(),
                Select::make('view_state')
                    ->options(ViewState::class)
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
