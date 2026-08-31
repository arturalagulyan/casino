<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Enums\Currency;
use App\Enums\GameOrder;
use App\Enums\ShopStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Identity')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, $set, $operation) => $operation === 'create'
                                ? $set('slug', Str::slug($state))
                                : null),
                        TextInput::make('slug')->required()->unique(ignoreRecord: true),
                        TextInput::make('frontend')
                            ->required()
                            ->default('default')
                            ->helperText('Front-end theme folder.'),
                        Select::make('currency')
                            ->options(Currency::options())
                            ->default(Currency::default()->value)
                            ->searchable()
                            ->required()
                            ->helperText('Base currency for this shop. Banks and reports can still be split per currency.'),
                        Select::make('owner_id')
                            ->relationship('owner', 'username')
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->options(ShopStatus::class)
                            ->default(ShopStatus::Active)
                            ->required(),
                    ]),

                Section::make('Payout')
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('rtp_percent')
                            ->label('Target RTP %')
                            ->numeric()->minValue(1)->maxValue(100)->default(90)->required(),
                        TextInput::make('max_win_multiplier')
                            ->numeric()->default(1000)->required()
                            ->helperText('Cap on a single win, × bet.'),
                        TextInput::make('player_limit')
                            ->numeric()->default(0)->required()
                            ->helperText('Bank overflow ceiling.'),
                        Select::make('order_by')
                            ->options(GameOrder::class)
                            ->default(GameOrder::Alphabetical)
                            ->required(),
                    ]),

                Section::make('Targeting')
                    ->columnSpanFull()
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TagsInput::make('allowed_countries')->placeholder('ISO codes'),
                        TagsInput::make('allowed_os'),
                        TagsInput::make('allowed_devices'),
                    ]),

                Section::make('Features')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Toggle::make('happy_hours_enabled')->default(true),
                        Toggle::make('progress_enabled')->default(true),
                        Toggle::make('invites_enabled')->default(true),
                        Toggle::make('welcome_bonuses_enabled')->default(true),
                        Toggle::make('sms_bonuses_enabled')->default(true),
                        Toggle::make('wheel_fortune_enabled')->default(true),
                    ]),
            ]);
    }
}
