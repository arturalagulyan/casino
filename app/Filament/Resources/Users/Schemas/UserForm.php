<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Currency;
use App\Enums\UserStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Account')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('username')
                            ->required()
                            ->maxLength(191),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email(),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule('min:8')
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText('Leave blank to keep the current password.'),
                        Select::make('status')
                            ->options(UserStatus::class)
                            ->default(UserStatus::Unconfirmed)
                            ->required(),
                        TextInput::make('first_name'),
                        TextInput::make('last_name'),
                        TextInput::make('phone')->tel(),
                        DatePicker::make('birthday'),
                    ]),

                Section::make('Access')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('role_id')
                            ->label('Primary role')
                            ->relationship('role', 'name')
                            ->required()
                            ->live(),
                        Select::make('roles')
                            ->label('Additional roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText('Extra roles beyond the primary one.'),
                        Toggle::make('is_blocked')
                            ->helperText('Blocked users cannot log in or play.'),
                    ]),

                Section::make('Placement')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Select::make('shop_id')
                            ->relationship('shop', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Home shop. Leave empty for staff above shop level.'),
                        Select::make('parent_id')
                            ->label('Parent (upline)')
                            ->relationship('parent', 'username')
                            ->searchable()
                            ->preload(),
                        Select::make('inviter_id')
                            ->relationship('inviter', 'username')
                            ->searchable()
                            ->preload(),
                        Select::make('currency')
                            ->options(Currency::options())
                            ->searchable()
                            ->helperText("Player's own currency — shop reports can split by it.")
                            ->default(fn () => null),
                        TextInput::make('language')->default('en')->maxLength(5),
                        TextInput::make('rating')->numeric()->default(0),
                    ]),

                Section::make('Seamless wallet')
                    ->columnSpanFull()
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('external_provider'),
                        TextInput::make('external_player_id'),
                        Toggle::make('is_demo_agent'),
                        Toggle::make('free_demo'),
                        Toggle::make('two_factor_enabled')->label('2FA enabled')->disabled(),
                    ]),
            ]);
    }
}
