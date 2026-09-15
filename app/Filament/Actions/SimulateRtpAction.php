<?php

namespace App\Filament\Actions;

use App\Enums\Currency;
use App\Enums\GameEngine;
use App\Models\Game;
use App\Services\GamePlay\RtpSimulator;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Test RTP" — headless-plays a game N times as a throwaway simulation player
 * (see {@see RtpSimulator}, same books-isolation as {@see PlayDemoAction}) and
 * reports total staked/paid + actual RTP against the game's configured target.
 */
class SimulateRtpAction
{
    public static function make(string $name = 'simulateRtp'): Action
    {
        return Action::make($name)
            ->label('Test RTP')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->visible(fn (Game $record) => self::available($record))
            ->modalHeading('Simulate spins')
            ->modalDescription('Plays as an isolated test player — real balances, the shop bank and jackpots are never touched. Reports the paytable\'s raw designed odds (the live self-correcting RTP loop is off the books here too, same as Play demo), so a short run can land off the target — that\'s expected, not a bug.')
            ->modalSubmitActionLabel('Run simulation')
            ->schema(fn (Game $record) => [
                TextInput::make('spins')
                    ->label('Number of spins')
                    ->numeric()
                    ->integer()
                    ->minValue(100)
                    ->maxValue(RtpSimulator::MAX_SPINS)
                    ->default(5000)
                    ->required()
                    ->helperText('Capped at '.number_format(RtpSimulator::MAX_SPINS).' per run.'),
                Select::make('betline')
                    ->label('Bet per line')
                    ->options(fn () => collect($record->config()->betOptions())
                        ->mapWithKeys(fn (float $v) => [(string) $v => $v]))
                    ->default(fn () => $record->config()->betOptions()[0] ?? 1)
                    ->required(),
                TextInput::make('lines')
                    ->label('Lines')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(fn () => $record->config()->lineCount())
                    ->default(fn () => $record->config()->lineCount())
                    ->helperText('Defaults to all paylines.'),
            ])
            ->action(function (array $data, Game $record) {
                try {
                    $result = app(RtpSimulator::class)->run(
                        $record,
                        (int) $data['spins'],
                        (float) $data['betline'],
                        filled($data['lines'] ?? null) ? (int) $data['lines'] : null,
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Simulation failed')->body($e->getMessage())->send();

                    return;
                }

                $currency = Currency::from($result['currency']);

                Notification::make()
                    ->success()
                    ->title(number_format($result['spins']).' spins · RTP '.$result['rtp'].'% (target '.$result['target_rtp'].'%)')
                    ->body(implode(' · ', [
                        'Stake/spin '.Money::format($result['stake_per_spin'], $currency),
                        'In '.Money::format($result['total_in'], $currency),
                        'Out '.Money::format($result['total_out'], $currency),
                        'Net '.Money::format($result['net'], $currency),
                        'Hit rate '.$result['hit_rate'].'%',
                        'Biggest win '.Money::format($result['biggest_win'], $currency),
                        $result['elapsed_seconds'].'s',
                    ]))
                    ->persistent()
                    ->send();
            });
    }

    private static function available(Game $record): bool
    {
        if (! auth()->user()?->hasPermission('games.manage')) {
            return false;
        }

        return $record->template->engine !== GameEngine::Seamless;
    }
}
