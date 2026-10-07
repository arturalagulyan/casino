<?php

namespace App\Filament\Actions;

use App\Filament\Resources\GameDesigns\GameDesignResource;
use App\Models\GameDesign;
use App\Models\Shop;
use App\Services\RoyalSpin\ArtPacks;
use App\Services\RoyalSpin\DesignPresets;
use App\Services\RoyalSpin\GameBuilder;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/** Game Builder actions: publish a design, demo it, check it, start one from a preset or a shipped game. */
class GameDesignActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label(fn (GameDesign $record) => $record->published_at ? 'Re-publish' : 'Publish')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->modalHeading(fn (GameDesign $record) => 'Publish '.$record->title)
            ->modalDescription('Saves the design, builds the game (math + front-end bundle) and adds it to the RoyalSpin category of the chosen shops. Re-publishing updates the live game in place.')
            ->modalSubmitActionLabel('Publish')
            ->schema([
                Select::make('shops')
                    ->multiple()
                    ->options(fn () => Shop::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('All shops')
                    ->helperText('Leave empty to add it to every shop.'),
                Toggle::make('apply_bets')
                    ->label('Also apply bet options & denomination to shops that already have this game')
                    ->helperText('Off: shops keep their own tuning; only new shops get the design\'s bets.'),
            ])
            ->action(function (array $data, GameDesign $record, Component $livewire) {
                if ($livewire instanceof EditRecord) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record->refresh();
                }
                try {
                    $out = app(GameBuilder::class)->publish(
                        $record,
                        array_map('intval', (array) ($data['shops'] ?? [])),
                        (bool) ($data['apply_bets'] ?? false),
                        auth()->user(),
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()->persistent()
                        ->title('Not published')
                        ->body(new HtmlString(nl2br(e($e->getMessage()))))
                        ->send();

                    return;
                }

                Notification::make()->success()
                    ->title($record->title.' is live')
                    ->body("Bundle {$out['bundle']} · in {$out['shops']} shop(s) · template {$out['template']->code}")
                    ->actions([
                        Action::make('demo')->label('Play demo')->url(route('games.demo', ['code' => $record->code]), shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    public static function playDemo(): Action
    {
        return Action::make('playDemo')
            ->label('Play demo')
            ->icon(Heroicon::OutlinedPlay)
            ->color('gray')
            ->visible(fn (GameDesign $record) => $record->template?->activeBundle !== null)
            ->url(fn (GameDesign $record) => route('games.demo', ['code' => $record->code]), shouldOpenInNewTab: true);
    }

    public static function check(): Action
    {
        return Action::make('check')
            ->label('Check')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->action(function (GameDesign $record, Component $livewire) {
                if ($livewire instanceof EditRecord) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record->refresh();
                }
                $builder = app(GameBuilder::class);
                $errors = $builder->validate($record);
                if ($errors) {
                    Notification::make()->warning()->persistent()->title(count($errors).' thing(s) to fix')
                        ->body(new HtmlString('• '.implode('<br>• ', array_map('e', $errors))))->send();

                    return;
                }
                $math = $builder->math($record);
                $strip = $math['reel_strips']['reelStrip1'] ?? [];
                $summary = $record->isCascade()
                    ? "{$record->reel_count}x{$record->row_count} pay anywhere · tiers ".implode('/', $math['tumble_config']['tiers']).' · '.$math['tumble_config']['free_spins'].' free spins'
                    : "{$record->reel_count}x{$record->row_count} · ".count($math['paylines']).' lines · min '.$math['min_match'].' in a row';
                Notification::make()->success()->title('Ready to publish')
                    ->body($summary.' · reel strip length '.count($strip))->send();
            });
    }

    public static function fromPreset(): Action
    {
        return Action::make('fromPreset')
            ->label('New from preset')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->modalHeading('Start a game from a preset')
            ->modalDescription('A complete, playable starting point — every value stays editable.')
            ->schema([
                Select::make('preset')->options(DesignPresets::options())->default('olympus-cascade')->required(),
                TextInput::make('code')->required()->regex('/^[A-Za-z][A-Za-z0-9]{2,40}$/')
                    ->unique(GameDesign::class, 'code')
                    ->placeholder('OlympusThunderRS')
                    ->helperText('Unique game code (letters/digits).'),
                TextInput::make('title')->placeholder('preset title')->maxLength(60),
            ])
            ->action(function (array $data, Component $livewire) {
                $design = GameDesign::create(array_merge(DesignPresets::get($data['preset']) ?? [], array_filter([
                    'code' => $data['code'],
                    'title' => $data['title'] ?? null,
                ])));
                $livewire->redirect(GameDesignResource::getUrl('edit', ['record' => $design]));
            });
    }

    public static function importShipped(): Action
    {
        return Action::make('importShipped')
            ->label('Re-design a RoyalSpin game')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->modalHeading('Re-design a shipped RoyalSpin game')
            ->modalDescription('Loads the game\'s math, symbols and colours into the builder. Keep the same code to change the live game\'s graphics/settings when you publish (e.g. switch it to the Olympus art and skin); use a new code to make a copy.')
            ->schema([
                Select::make('game')->required()->options(function () {
                    $out = [];
                    foreach (File::directories(ArtPacks::root().'/games') as $dir) {
                        if (is_file($dir.'/math.json')) {
                            $meta = json_decode((string) file_get_contents($dir.'/game.json'), true) ?: [];
                            $out[basename($dir)] = ($meta['title'] ?? basename($dir)).' ('.basename($dir).')';
                        }
                    }

                    return $out;
                }),
                TextInput::make('code')->label('Design code')->placeholder('same as the game')
                    ->regex('/^[A-Za-z][A-Za-z0-9]{2,40}$/')
                    ->helperText('Empty = the same code (re-skins the live game on publish).'),
            ])
            ->action(function (array $data, Component $livewire) {
                $code = ($data['code'] ?? null) ?: $data['game'];
                if (GameDesign::where('code', $code)->exists()) {
                    Notification::make()->warning()->title("A design with code {$code} already exists")->send();

                    return;
                }
                $design = app(GameBuilder::class)->importShippedGame($data['game'], $code);
                $livewire->redirect(GameDesignResource::getUrl('edit', ['record' => $design]));
            });
    }
}
