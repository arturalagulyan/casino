<?php

namespace App\Filament\Resources\GameDesigns\Schemas;

use App\Filament\Forms\JsonField;
use App\Models\GameDesign;
use App\Services\RoyalSpin\ArtPacks;
use App\Services\RoyalSpin\GameBuilder;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The Game Builder form: everything about a RoyalSpin game an admin can
 * decide — grid, mechanic, symbols (art, role, pays, how often they land),
 * paylines, features, look & feel (skin, colours, uploads) and bets / odds.
 */
class GameDesignForm
{
    private const array GRIDS = [
        '3x3' => '3 x 3', '5x3' => '5 x 3', '5x4' => '5 x 4', '6x4' => '6 x 4',
        '6x5' => '6 x 5 (Gates-style)', '7x7' => '7 x 7',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('builder')
                    ->persistTabInQueryString()
                    ->tabs([
                        self::gameTab(),
                        self::symbolsTab(),
                        self::linesTab(),
                        self::featuresTab(),
                        self::lookTab(),
                        self::betsTab(),
                    ]),
            ]);
    }

    private static function packs(): ArtPacks
    {
        return app(ArtPacks::class);
    }

    private static function packUrl(?string $pack, ?string $rel): ?string
    {
        return $pack && $rel ? route('admin.royalspin-packs.file', ['pack' => $pack, 'path' => $rel]) : null;
    }

    private static function cascade(Get $get, string $up = ''): bool
    {
        return $get($up.'mechanic') === GameDesign::MECHANIC_CASCADE;
    }

    // ------------------------------------------------------------------ game

    private static function gameTab(): Tab
    {
        return Tab::make('Game')->icon('heroicon-o-puzzle-piece')->schema([
            Section::make()->columns(3)->schema([
                TextInput::make('code')
                    ->required()->unique(ignoreRecord: true)->maxLength(41)
                    ->regex('/^[A-Za-z][A-Za-z0-9]{2,40}$/')
                    ->helperText('Unique game code, letters/digits — e.g. OlympusThunderRS. Ending in RS keeps lobby titles clean.'),
                TextInput::make('title')->required()->maxLength(60)->helperText('Shown in the lobby and drawn as the in-game logo (unless you upload one).'),
                Select::make('mechanic')
                    ->options([
                        GameDesign::MECHANIC_CASCADE => 'Pay anywhere + tumble (cascade)',
                        GameDesign::MECHANIC_LINES => 'Paylines (classic reels)',
                    ])
                    ->default(GameDesign::MECHANIC_CASCADE)->required()->live(),
            ]),
            Section::make('Board')->columns(3)->schema([
                Select::make('grid_preset')
                    ->label('Quick grid')
                    ->options(self::GRIDS)
                    ->placeholder('custom')
                    ->dehydrated(false)
                    ->live()
                    ->afterStateHydrated(fn (Select $component, Get $get) => $component->state(isset(self::GRIDS[$get('reel_count').'x'.$get('row_count')]) ? $get('reel_count').'x'.$get('row_count') : null))
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        if ($state && str_contains($state, 'x')) {
                            [$reels, $rows] = explode('x', $state);
                            $set('reel_count', (int) $reels);
                            $set('row_count', (int) $rows);
                        }
                    }),
                TextInput::make('reel_count')->label('Reels (columns)')->numeric()->minValue(3)->maxValue(8)->default(6)->required()->live(onBlur: true),
                TextInput::make('row_count')->label('Rows')->numeric()->minValue(1)->maxValue(8)->default(5)->required()->live(onBlur: true),
            ]),
            Section::make('Art & skin')->columns(2)->schema([
                Select::make('art_pack')
                    ->label('Art pack')
                    ->options(fn () => self::packs()->options())
                    ->default('olympus')
                    ->live()
                    ->helperText('Stock artwork the symbols, backgrounds, character and music come from. Any single item can be replaced by an upload.'),
                Select::make('skin')
                    ->options(GameBuilder::SKINS)
                    ->default('olympus')->required()
                    ->helperText('Screen layout + frame. Olympus = Gates-style temple frame with the character beside the board.'),
                Html::make(fn (Get $get) => self::packPreview($get('art_pack')))->columnSpanFull(),
                Actions::make([
                    Action::make('fillFromPack')
                        ->label('Fill symbols & colours from this pack')
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Replaces the symbol list with the pack\'s symbols (with starter pays / weights) and resets the colours to the pack\'s theme.')
                        ->action(function (Get $get, Set $set) {
                            $pack = self::packs()->get($get('art_pack'));
                            if (! $pack) {
                                return;
                            }
                            $set('symbols', self::starterSymbols($pack, (bool) self::cascade($get), (int) $get('reel_count')));
                            $t = $pack['theme'];
                            $set('theme.accent', $t['accent'] ?? '#ffd84a');
                            $set('theme.frame', $t['frame'] ?? ['#fff2a8', '#d4a017', '#7a5200']);
                            $set('theme.reel_bg', $t['reelBg'] ?? ['#14141c', '#06060a']);
                            if (! empty($t['font'])) {
                                $set('theme.font', $t['font']);
                            }
                            $set('skin', $pack['skin']);
                            Notification::make()->success()->title('Symbols filled from '.$pack['title'])->send();
                        }),
                ])->columnSpanFull(),
            ]),
        ]);
    }

    private static function packPreview(?string $key): HtmlString
    {
        $pack = self::packs()->get($key);
        if (! $pack) {
            return new HtmlString('');
        }
        $img = fn (?string $rel, string $style) => ($u = self::packUrl($key, $rel)) ? '<img src="'.e($u).'" style="'.$style.'">' : '';
        $syms = '';
        foreach ($pack['symbols'] as $s) {
            $syms .= '<figure style="margin:0;text-align:center;width:64px">'.$img($s['file'], 'width:56px;height:56px').'<figcaption style="font-size:10px;opacity:.7">'.e($s['name']).'</figcaption></figure>';
        }

        return new HtmlString('<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">'
            .'<div style="position:relative;width:256px;height:144px;border-radius:8px;overflow:hidden;flex:none">'
            .$img($pack['background'], 'width:100%;height:100%;object-fit:cover')
            .$img($pack['character'], 'position:absolute;right:4px;bottom:0;height:130px')
            .'</div><div style="display:flex;flex-wrap:wrap;gap:6px;max-width:640px">'.$syms.'</div></div>');
    }

    /**
     * A pack's symbols as a starter symbol list.
     *
     * @param  array<string, mixed>  $pack
     * @return array<string, array<string, mixed>>
     */
    private static function starterSymbols(array $pack, bool $cascade, int $reels): array
    {
        $out = [];
        $plain = array_values(array_filter($pack['symbols'], fn ($s) => in_array($s['kind'], ['low', 'high'], true)));
        $n = max(1, count($plain));
        foreach ($pack['symbols'] as $s) {
            $rank = array_search($s, $plain, true);
            $f = $rank === false ? 1 : 1 + 4 * $rank / $n;   // 1 … 5 by value
            $role = match ($s['kind']) {
                'wild' => $cascade ? 'regular' : 'wild',
                'scatter' => 'scatter',
                'multiplier' => $cascade ? 'multiplier' : null,
                default => 'regular',
            };
            if ($role === null) {
                continue;
            }
            $pays = match (true) {
                $role === 'scatter' => $cascade ? [] : array_pad([0, 0, 0, 2, 10, 50], $reels + 1, 0),
                $role === 'multiplier' => [],
                $cascade => [round(0.25 * $f * $f, 2), round(0.6 * $f * $f, 2), round(2 * $f * $f, 2)],
                default => array_slice(array_pad([0, 0, 0, round(4 * $f), round(15 * $f * $f / 2), round(50 * $f * $f)], $reels + 1, round(100 * $f * $f)), 0, $reels + 1),
            };
            $out[(string) Str::uuid()] = [
                'name' => $s['name'],
                'role' => $role,
                'art' => $s['key'],
                'image' => null,
                'pays' => implode(', ', $pays),
                'weight' => match ($role) {
                    'scatter' => $cascade ? 2 : 1, 'multiplier' => 2, 'wild' => 2, default => max(2, (int) round(15 - 2 * $f))
                },
                'weight_free' => $role === 'multiplier' ? 3 : null,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ symbols

    private static function symbolsTab(): Tab
    {
        return Tab::make('Symbols')->icon('heroicon-o-squares-2x2')->schema([
            Html::make(fn (Get $get) => new HtmlString('<p style="font-size:13px;opacity:.8">'
                .(self::cascade($get)
                    ? '<b>Pays</b> = × TOTAL bet for each pay tier (see Features → pay tiers, e.g. 8–9 / 10–11 / 12+ symbols anywhere). Scatter pays and the free-spin trigger are set on the Features tab; multiplier orbs carry the values set there.'
                    : '<b>Pays</b> = × LINE bet, listed for 0, 1, 2 … '.(int) $get('reel_count').' symbols in a row (e.g. <code>0, 0, 0, 5, 20, 80</code> pays 3, 4 and 5 in a row). A scatter\'s pays are × TOTAL bet, anywhere on the board.')
                .' <b>Weight</b> = how many copies sit on each reel strip — higher lands more often. <b>Free weight</b> overrides it during free spins.</p>')),
            Repeater::make('symbols')
                ->hiddenLabel()
                ->reorderable()
                ->collapsible()
                ->cloneable()
                ->itemLabel(fn (array $state) => trim(($state['name'] ?? 'Symbol').' · '.(GameBuilder::ROLES[$state['role'] ?? 'regular'] ?? '')))
                ->addActionLabel('Add symbol')
                ->minItems(3)
                ->columns(12)
                ->schema([
                    TextInput::make('name')->required()->maxLength(40)->columnSpan(3),
                    Select::make('role')->options(GameBuilder::ROLES)->default('regular')->required()->live()->columnSpan(3),
                    Select::make('art')
                        ->label('Art (from pack)')
                        ->allowHtml()
                        ->searchable(false)
                        ->options(function (Get $get) {
                            $key = $get('../../art_pack');
                            $out = [];
                            foreach (self::packs()->get($key)['symbols'] ?? [] as $s) {
                                $out[$s['key']] = '<span style="display:inline-flex;align-items:center;gap:8px"><img src="'.e(self::packUrl($key, $s['file'])).'" style="width:30px;height:30px">'.e($s['name']).'</span>';
                            }

                            return $out;
                        })
                        ->columnSpan(3),
                    FileUpload::make('image')
                        ->label('…or upload')
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/webp', 'image/jpeg', 'image/svg+xml'])
                        ->disk('public')->directory('game-builder/symbols')->visibility('public')
                        ->maxSize(2048)
                        ->imagePreviewHeight('60')
                        ->helperText('Square PNG/WebP/SVG, transparent background, ≥200 px.')
                        ->columnSpan(3),
                    TextInput::make('pays')
                        ->label('Pays')
                        ->placeholder(fn (Get $get) => self::cascade($get, '../../') ? '0.5, 1, 5' : '0, 0, 0, 5, 20, 80')
                        ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)
                        ->dehydrateStateUsing(fn ($state) => GameBuilder::numbers($state))
                        ->hidden(fn (Get $get) => $get('role') === 'multiplier' || ($get('role') === 'scatter' && self::cascade($get, '../../')))
                        ->columnSpan(6),
                    TextInput::make('weight')->numeric()->minValue(0)->maxValue(200)->default(8)->required()->columnSpan(3),
                    TextInput::make('weight_free')->label('Free weight')->numeric()->minValue(0)->maxValue(200)->placeholder('same')->columnSpan(3),
                ]),
        ]);
    }

    // ------------------------------------------------------------------ paylines

    private static function linesTab(): Tab
    {
        return Tab::make('Paylines')->icon('heroicon-o-arrows-right-left')
            ->visible(fn (Get $get) => ! self::cascade($get))
            ->schema([
                Section::make()->schema([
                    TextInput::make('settings.payline_count')
                        ->label('Number of paylines')
                        ->numeric()->minValue(1)->maxValue(100)->default(10)
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get) => 'Generated for the board: straight rows first, then V shapes and zig-zags. A '.(int) $get('reel_count').'x'.(int) $get('row_count').' board has '
                            .count(app(GameBuilder::class)->allPaylines(max(1, (int) $get('reel_count')), max(1, (int) $get('row_count')))).' distinct lines.'),
                    Html::make(fn (Get $get) => self::linesPreview($get)),
                    JsonField::make('paylines')
                        ->label('Custom paylines (optional)')
                        ->rows(5)
                        ->helperText('Overrides the generated lines. A JSON list of lines, each the row (0 = top) per reel, e.g. [[1,1,1,1,1],[0,0,0,0,0],[0,1,2,1,0]].'),
                ]),
            ]);
    }

    private static function linesPreview(Get $get): HtmlString
    {
        $reels = max(1, min(8, (int) $get('reel_count')));
        $rows = max(1, min(8, (int) $get('row_count')));
        $custom = $get('paylines');
        if (is_string($custom)) {
            $custom = json_decode($custom, true);
        }
        $lines = is_array($custom) && $custom !== []
            ? $custom
            : array_slice(app(GameBuilder::class)->allPaylines($reels, $rows), 0, max(1, (int) $get('settings.payline_count')));

        $html = '<div style="display:flex;flex-wrap:wrap;gap:8px">';
        foreach (array_slice($lines, 0, 60) as $i => $line) {
            $cells = '';
            for ($r = 0; $r < $rows; $r++) {
                for ($c = 0; $c < $reels; $c++) {
                    $on = (int) ($line[$c] ?? -1) === $r;
                    $cells .= '<i style="display:block;height:8px;border-radius:2px;background:'.($on ? '#d4af37' : '#ffffff1a').'"></i>';
                }
            }
            $html .= '<div style="width:76px;font-size:10px;text-align:center;opacity:.85">'.($i + 1)
                .'<div style="display:grid;gap:2px;grid-template-columns:repeat('.$reels.',1fr);margin-top:2px">'.$cells.'</div></div>';
        }

        return new HtmlString($html.'</div>');
    }

    // ------------------------------------------------------------------ features

    private static function featuresTab(): Tab
    {
        $cascade = fn (Get $get) => self::cascade($get);
        $lines = fn (Get $get) => ! self::cascade($get);

        return Tab::make('Features')->icon('heroicon-o-bolt')->schema([
            Section::make('Pay anywhere + tumble')->columns(3)->visible($cascade)->schema([
                TextInput::make('settings.tiers')->label('Pay tiers')->default('8, 10, 12')
                    ->helperText('Symbol counts where pays step up. "8, 10, 12" = 8–9, 10–11 and 12+ anywhere → three pay values per symbol.'),
                TextInput::make('settings.cascade_lines')->label('Bet multiplier')->numeric()->minValue(1)->default(20)
                    ->helperText('Total bet = coin bet × this. 20 is standard.'),
                TextInput::make('settings.scatter_pays')->label('Scatter pays')->default('4:3, 5:5, 6:100')
                    ->helperText('count:× total bet, e.g. "4:3, 5:5, 6:100".'),
            ]),
            Section::make('Free spins')->columns(4)->visible($cascade)->schema([
                TextInput::make('settings.trigger')->label('Scatters to trigger')->numeric()->minValue(1)->default(4),
                TextInput::make('settings.free_spins')->label('Free spins awarded')->numeric()->minValue(1)->default(15),
                TextInput::make('settings.retrigger')->label('Scatters to retrigger')->numeric()->minValue(1)->default(3),
                TextInput::make('settings.retrigger_spins')->label('Extra spins on retrigger')->numeric()->minValue(0)->default(5),
            ]),
            Section::make('Multiplier orbs')->columns(3)->visible($cascade)
                ->description('Needs a symbol with the "Multiplier orb" role.')
                ->schema([
                    Textarea::make('settings.multiplier_values')->label('Values pool')->rows(2)->columnSpanFull()
                        ->default('2, 2, 3, 3, 4, 5, 6, 8, 10, 12, 15, 20, 25, 50, 100, 250, 500')
                        ->helperText('Each landing orb picks one value at random — repeat a value to make it more common.'),
                    Toggle::make('settings.multiplier_in_base')->label('Also in the base game')->default(true),
                    Toggle::make('settings.multiplier_accumulate')->label('Total multiplier in free spins')->default(true)
                        ->helperText('Gates-style: every orb in free spins adds to a running total that multiplies all later wins.'),
                ]),
            Section::make('Side bets')->columns(2)->visible($cascade)->schema([
                TextInput::make('settings.buy_feature')->label('Buy free spins price (× total bet)')->numeric()->minValue(0)->default(100)
                    ->helperText('0 = no feature buy.'),
                TextInput::make('settings.ante_bet')->label('Ante bet factor')->numeric()->minValue(0)->step(0.05)->default(1.25)
                    ->helperText('0 = off. 1.25 = pay 25% more for twice the free-spin chance.'),
            ]),

            Section::make('Wild & lines')->columns(3)->visible($lines)->schema([
                TextInput::make('settings.wild_multiplier')->label('Wild multiplier')->numeric()->minValue(1)->default(1)
                    ->helperText('Wins completed by the wild are multiplied by this.'),
            ]),
            Section::make('Free spins')->columns(3)->visible($lines)->schema([
                Toggle::make('settings.free_spins_enabled')->label('Scatters award free spins')->default(true)->live()->columnSpanFull(),
                TextInput::make('settings.free_spins_count')->label('Free spins')->numeric()->minValue(1)->default(10)
                    ->visible(fn (Get $get) => $get('settings.free_spins_enabled')),
                TextInput::make('settings.free_spins_table')->label('By scatter count')->placeholder('3:10, 4:15, 5:20')
                    ->helperText('Optional — different counts per number of scatters.')
                    ->visible(fn (Get $get) => $get('settings.free_spins_enabled')),
                TextInput::make('settings.free_spins_multiplier')->label('Win multiplier in free spins')->numeric()->minValue(1)->default(2)
                    ->visible(fn (Get $get) => $get('settings.free_spins_enabled')),
            ]),
            Section::make('Gamble')->visible($lines)->schema([
                Toggle::make('settings.gamble_enabled')->label('Red / black gamble after a win')->default(true),
            ]),
            Section::make('Texts')->collapsed()->schema([
                TextInput::make('settings.free_spins_note')->label('Free-spins intro line')->maxLength(160)
                    ->helperText('Optional — replaces the generated line on the free-spins start screen.'),
                Textarea::make('settings.features_extra')->label('Extra rules / features (one per line)')->rows(3),
            ]),
        ]);
    }

    // ------------------------------------------------------------------ look & feel

    private static function lookTab(): Tab
    {
        $upload = fn (string $name, string $label, array $types, string $help) => FileUpload::make("assets.{$name}")
            ->label($label)
            ->acceptedFileTypes($types)
            ->disk('public')->directory('game-builder/assets')->visibility('public')
            ->maxSize(8192)
            ->helperText($help);
        $images = ['image/png', 'image/webp', 'image/jpeg', 'image/svg+xml'];
        $audio = ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp3'];

        return Tab::make('Look & feel')->icon('heroicon-o-paint-brush')->schema([
            Section::make('Colours')->columns(4)->schema([
                ColorPicker::make('theme.accent')->label('Accent')->default('#ffd76a')
                    ->helperText('Messages, win amounts, buttons.'),
                ColorPicker::make('theme.frame.0')->label('Frame light')->default('#fffbe0'),
                ColorPicker::make('theme.frame.1')->label('Frame mid')->default('#e0a82e'),
                ColorPicker::make('theme.frame.2')->label('Frame dark')->default('#7a4a00'),
                ColorPicker::make('theme.reel_bg.0')->label('Board top')->rgba()->default('rgba(58,10,74,0.8)'),
                ColorPicker::make('theme.reel_bg.1')->label('Board bottom')->rgba()->default('rgba(26,4,40,0.9)'),
                ColorPicker::make('theme.jewel')->label('Frame jewels (Olympus)')->default('#3ab4ff'),
                TextInput::make('theme.line_colors')->label('Payline colours')->placeholder('#ffd84a, #ff5a7a, #5affb0')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state),
            ]),
            Section::make('Typography & logo')->columns(3)->schema([
                Select::make('theme.font')->label('Font')->options(GameBuilder::FONTS)->placeholder('pack default'),
                Select::make('theme.logo_style')->label('Generated logo')->options(['plaque' => 'Gold-rimmed plaque with lightning', 'gold' => 'Gold lettering'])->default('plaque'),
                Toggle::make('settings.show_character')->label('Show the character beside the board')->default(true)
                    ->helperText('Olympus skin. Lightning strikes from the character onto multiplier orbs.'),
            ]),
            Section::make('Your own art & music')->columns(2)
                ->description('Optional — anything left empty comes from the art pack (the logo is generated from the title).')
                ->schema([
                    $upload('background', 'Background', $images, '1280×720 (16:9).'),
                    $upload('background_free', 'Free-spins background', $images, '1280×720. Empty = same as the background.'),
                    $upload('logo', 'Logo', $images, '~640×170, transparent.'),
                    $upload('character', 'Character', $images, 'Tall transparent picture (~420×680) standing right of the board.'),
                    $upload('poster', 'Lobby poster', $images, 'Square-ish thumbnail. Empty = composed automatically.'),
                    $upload('music', 'Music loop', $audio, 'MP3/OGG/WAV, loops seamlessly.'),
                    $upload('music_free', 'Free-spins music', $audio, 'Plays during the feature.'),
                ]),
        ]);
    }

    // ------------------------------------------------------------------ bets & odds

    private static function betsTab(): Tab
    {
        return Tab::make('Bets & odds')->icon('heroicon-o-banknotes')->schema([
            Section::make('Bets')->columns(2)->schema([
                TextInput::make('settings.bet_options')->label('Coin bet options')->default('1, 2, 5, 10, 20, 50, 100, 250')
                    ->helperText('Coin values the player steps through. Stake = coin × denomination × lines (or the bet multiplier).'),
                TextInput::make('settings.denomination')->label('Denomination (EUR)')->numeric()->step(0.01)->minValue(0.0001)->default(0.01),
            ]),
            Section::make('Odds')->columns(3)
                ->description('The target RTP comes from the shop (or the per-shop game). These set the base rhythm; the engine\'s RTP control steers payouts toward the target.')
                ->schema([
                    Select::make('settings.volatility')->options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'])->default('high'),
                    TextInput::make('settings.hit_every')->label('A win every ~N spins')->numeric()->minValue(1)->placeholder('by volatility'),
                    TextInput::make('settings.feature_every')->label('Free spins every ~N spins')->numeric()->minValue(2)->placeholder('by volatility'),
                ]),
            Grid::make(1)->schema([
                Html::make(new HtmlString('<p style="font-size:12px;opacity:.7">Use <b>Test RTP</b> on the published game template to simulate thousands of spins before going live.</p>')),
            ]),
        ]);
    }
}
