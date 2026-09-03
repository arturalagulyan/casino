<?php

namespace App\Filament\Resources\Games\Schemas;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Enums\GameLabel;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use App\Filament\Forms\JsonField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A game is one template published into one shop. The form is per-shop tuning —
 * anything left blank inherits from the template's engine spec.
 */
class GameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Placement')
                    ->columns(2)
                    ->schema([
                        Select::make('shop_id')->relationship('shop', 'name')->searchable()->required(),
                        Select::make('template_id')->relationship('template', 'title')->searchable()->required(),
                        TextInput::make('title')->placeholder('inherit from template'),
                        Select::make('label')->options(GameLabel::class)->placeholder('none'),
                        Select::make('categories')->relationship('categories', 'title')
                            ->multiple()->preload()->searchable(),
                        Toggle::make('is_visible')->default(true),
                        TextInput::make('sort_order')->numeric()->default(0),
                    ]),

                Section::make('Payout & liquidity')
                    ->columns(3)
                    ->schema([
                        TextInput::make('rtp_percent')->label('Target RTP %')->numeric()
                            ->minValue(1)->maxValue(100)->placeholder('shop default')
                            ->helperText('Overrides the shop RTP for this game.'),
                        TextInput::make('max_win_multiplier')->numeric()->placeholder('shop default'),
                        Select::make('bank_type')->options(BankType::class)->default('slots')->required()
                            ->helperText('Legacy gamebank — which pool funds wins.'),
                        Select::make('jackpot_id')->relationship('jackpot', 'name')
                            ->searchable()->placeholder('none')->label('Jackpot'),
                        TextInput::make('wild_multiplier')->numeric()->placeholder('template'),
                        TextInput::make('free_spins_count')->numeric()->placeholder('template'),
                        TextInput::make('reserve_percent')->label('Gamble win chance (1/N)')
                            ->numeric()->default(0)->helperText('Legacy rezerv. 0 = use template.'),
                        TextInput::make('cask')->numeric()->default(0),
                    ]),

                Section::make('Bet & denomination')
                    ->columns(2)
                    ->schema([
                        JsonField::make('bet_options')->rows(2)->helperText('JSON array — blank inherits the template.'),
                        TextInput::make('denomination')->numeric()->default(1)->required(),
                        Select::make('pricing_currency')
                            ->options(Currency::options())
                            ->searchable()
                            ->helperText('Blank inherits the template. Currency the bet ladder is priced in; other currencies scale by FX.'),
                        Select::make('scale_mode')->options(ScaleMode::class)->default(''),
                        Select::make('view_state')->options(ViewState::class)->default(''),
                    ]),

                Section::make('Jackpots / firepots')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        JsonField::make('jackpot_chances', 'Firepot chances')
                            ->helperText('{ "chance1":2000,"count1":5, "chance2":…, "chance3":… } — 1/N drop chance & symbol count per firepot (legacy chanceFirepot*/fireCount*).'),
                    ]),

                Section::make('Engine overrides')
                    ->description('Blank = inherit the template. Same shapes as the template.')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        JsonField::make('win_chances')->helperText('1/N win-chance tables.'),
                        JsonField::make('free_spins_table')->rows(2)->helperText('Free spins per scatter count, e.g. [0,0,0,10,10,10].'),
                        JsonField::make('win_distribution')->rows(3)->helperText('Win-size curve params.'),
                    ]),
            ]);
    }
}
