<?php

namespace App\Filament\Resources\GameTemplates\Schemas;

use App\Enums\BankType;
use App\Enums\ClientProtocol;
use App\Enums\Currency;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use App\Enums\Volatility;
use App\Filament\Forms\JsonField;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A game template is the shared engine spec — the "group" every game cloned from
 * it runs on. Everything the legacy VanguardLTE SlotSettings hardcoded is a field
 * here; per-shop tuning happens on the Game.
 */
class GameTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')->required()->unique(ignoreRecord: true)
                            ->helperText('Clean name, e.g. ActionMoney. Family/provider grouping is done with Categories.'),
                        TextInput::make('title')->required(),
                        Select::make('engine')->options(GameEngine::class)->default('internal')->required(),
                        Select::make('client_protocol')->options(ClientProtocol::class)->placeholder('inherit from category')
                            ->helperText('How the bundle talks to us. Blank = inherit from the game\'s category (e.g. "Egt" → WebSocket), else Standard.'),
                        Select::make('device')->options(Device::class)->default('both')->required(),
                        Select::make('bank_type')->options(BankType::class)->default('slots')->required()
                            ->helperText('Default liquidity pool (legacy gamebank).'),
                        Toggle::make('is_active')->default(true),
                        FileUpload::make('poster_path')
                            ->label('Poster')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('game-posters')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->helperText('Lobby / admin thumbnail (legacy /frontend/<theme>/ico/<name>.jpg). ~520×300.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Grid & symbols')
                    ->columns(3)
                    ->schema([
                        TextInput::make('reel_count')->numeric()->default(5)->required(),
                        TextInput::make('row_count')->numeric()->default(3)->required(),
                        TextInput::make('symbol_count')->numeric()->default(9)->required(),
                        TextInput::make('wild_symbol')->numeric()->placeholder('none'),
                        TextInput::make('scatter_symbol')->numeric()->placeholder('none'),
                        TextInput::make('bonus_symbol')->numeric()->placeholder('none'),
                        TextInput::make('wild_multiplier')->numeric()->default(1)->required()
                            ->helperText('Line-win multiplier when a wild completes it (legacy slotWildMpl).'),
                        TextInput::make('min_match')->numeric()->default(3)->required()
                            ->helperText('Smallest paying run left-to-right (3 for most; EGT "Action Money" pays 2).'),
                        Select::make('volatility')->options(Volatility::class)->default('medium')->required()
                            ->helperText('Presets the win-size curve & base hit-rate. Override below.'),
                        JsonField::make('symbols')->rows(2)
                            ->helperText('Playable symbol ids (legacy SymbolGame), e.g. [0,1,2,3,4,5,6,7,8]. Blank = 0…symbol_count-1.'),
                    ]),

                Section::make('Bonus & free spins')
                    ->columns(3)
                    ->schema([
                        Toggle::make('has_bonus')->live()->helperText('Legacy slotBonus.'),
                        TextInput::make('bonus_type')->numeric()->default(1)
                            ->visible(fn (Get $get) => $get('has_bonus')),
                        TextInput::make('scatter_type')->numeric()->default(0)
                            ->visible(fn (Get $get) => $get('has_bonus')),
                        Toggle::make('has_free_spins')->live(),
                        TextInput::make('free_spins_count')->numeric()->default(10)
                            ->visible(fn (Get $get) => $get('has_free_spins'))
                            ->helperText('Fixed grant.'),
                        TextInput::make('free_spins_multiplier')->numeric()->default(1)
                            ->visible(fn (Get $get) => $get('has_free_spins')),
                        JsonField::make('free_spins_table')->rows(2)
                            ->visible(fn (Get $get) => $get('has_free_spins'))
                            ->helperText('Grant per scatter count (legacy slotFreeCount array), e.g. [0,0,0,10,10,10] → 3 scatters = 10. Blank = fixed grant.'),
                        Toggle::make('split_screen'),
                        JsonField::make('bonus_config')->rows(6)->columnSpanFull()
                            ->visible(fn (Get $get) => $get('has_bonus'))
                            ->helperText('Feature flows per trigger symbol. { "triggers": { "10": {"flow":"pick_multiplier_freespins","min":3} }, "pick_multiplier_freespins": {"multiplier_range":[1,5],"free_spins_range":[5,12],"extra_wild_range":[5,7]}, "pick_money": {"multipliers":[…],"picks":3}, "gamble": {"type":"red_black","steps":5} }'),
                    ]),

                Section::make('Gamble')
                    ->columns(3)
                    ->schema([
                        Toggle::make('has_gamble')->default(true)->live(),
                        TextInput::make('gamble_type')->numeric()->default(1)
                            ->visible(fn (Get $get) => $get('has_gamble')),
                        TextInput::make('gamble_win_chance')->numeric()->default(4)
                            ->visible(fn (Get $get) => $get('has_gamble'))
                            ->helperText('1 in N chance the gamble step wins (legacy rezerv).'),
                    ]),

                Section::make('Defaults for cloned games')
                    ->columns(2)
                    ->schema([
                        JsonField::make('default_bet_options', 'Bet options')
                            ->rows(2)->helperText('JSON array, e.g. [10, 20, 50, 100, 200]. Fixed "credit" values — the same for every currency.'),
                        TextInput::make('default_denomination')->numeric()->default(1)->required(),
                        Select::make('pricing_currency')
                            ->options(Currency::options())
                            ->default('EUR')->required()->searchable()
                            ->helperText('Currency the bet options / denomination are priced in. Other currencies scale the denomination by the FX rate.'),
                        Select::make('scale_mode')->options(ScaleMode::class)->default(''),
                        Select::make('view_state')->options(ViewState::class)->default(''),
                    ]),

                Section::make('Engine data')
                    ->description('Leave blank to run on generated defaults. See docs/GAME-ENGINE.md for shapes.')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        JsonField::make('paytable')
                            ->helperText('{ "0":[0,0,0,5,20,100], … } — payout per 1/2/3/4/5/6-of-a-kind, × betline.'),
                        JsonField::make('reel_strips')
                            ->helperText('{ "reelStrip1":[…ints], …, "reelStripBonus1":[…] } — the symbol strips.'),
                        JsonField::make('paylines')
                            ->helperText('[[1,1,1,1,1], [0,0,0,0,0], …] — row index per reel.'),
                        JsonField::make('win_chances')
                            ->helperText('{ "spin": { "line10": {"74_80":12,"82_88":9,"90_96":6}, … }, "bonus": {…} } — 1/N win chance by line count & shop RTP band.'),
                        JsonField::make('layout')->rows(3)
                            ->helperText('Client-side config passed straight to the front-end: reel positions, key map, sounds, hidden buttons, exit URL…'),
                    ]),

                Section::make('RTP tuning (advanced)')
                    ->description('The garant / feedback-loop knobs — legacy GetSpinSettings. Blank = Volatility defaults.')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextInput::make('rtp_control_window')->numeric()->default(200)
                            ->helperText('Spins between RTP self-corrections (legacy RtpControlCount).'),
                        JsonField::make('win_distribution')->rows(4)
                            ->helperText('Win-size curve: { "small_prob":0.7, "small_floor":0.15, "small_span":0.85, "tail_exp":2, "tail_scale":4, "min_factor":0.1, "budget_frac":0.85, "hit_bonus":1 }.'),
                        JsonField::make('rtp_control')->rows(3)
                            ->helperText('Correction knobs: { "cold_spin_chance":20, "cold_bonus_chance":5000, "correction_max_win":5, "clamp_spins":[25,50] }.'),
                    ]),
            ]);
    }
}
