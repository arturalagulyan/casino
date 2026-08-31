<?php

namespace App\Filament\Resources\ApiKeys\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ApiKeyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'name')
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->helperText('Label for the provider this key belongs to.'),
                TextInput::make('key')
                    ->label('API key (keygen)')
                    ->required()
                    ->default(fn () => Str::random(25))
                    ->unique(ignoreRecord: true)
                    ->helperText('Sent by the provider in the "api" header on game-launch calls.'),
                TextInput::make('secret')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText('Shared secret for signing callbacks. Leave blank to keep.'),
                TagsInput::make('allowed_ips')
                    ->label('Allowed IPs')
                    ->placeholder('1.2.3.4')
                    ->helperText('Empty = any IP. Legacy stored a single value.')
                    ->columnSpanFull(),
                TextInput::make('callback_url')
                    ->label('Callback endpoint')
                    ->url()
                    ->helperText('Where we POST bet/win results (legacy "endpoint").')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
