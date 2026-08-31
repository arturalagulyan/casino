<?php

namespace App\Filament\Actions;

use App\Models\Jackpot;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class JackpotActions
{
    /** Award the whole pot to a winner and reset it (legacy JPGController@immediately). */
    public static function payout(string $name = 'payout'): Action
    {
        return Action::make($name)
            ->label('Pay out now')
            ->icon(Heroicon::OutlinedGift)
            ->color('success')
            ->visible(fn () => (bool) auth()->user()?->hasPermission('jpgame.edit'))
            ->requiresConfirmation()
            ->modalDescription('This credits the winner with the full pot and resets the jackpot to zero.')
            ->schema([
                Select::make('winner_id')
                    ->label('Winner')
                    ->options(fn (Jackpot $record) => User::query()
                        ->when($record->shop_id, fn ($q) => $q->where('shop_id', $record->shop_id))
                        ->whereHas('roles', fn ($q) => $q->where('slug', 'user'))
                        ->orderBy('username')
                        ->limit(200)
                        ->pluck('username', 'id'))
                    ->searchable()
                    ->default(fn (Jackpot $record) => $record->last_winner_id)
                    ->required(),
            ])
            ->action(function (array $data, Jackpot $record) {
                try {
                    $txn = app(Ledger::class)->payoutJackpot(
                        $record,
                        User::find($data['winner_id']),
                        auth()->user(),
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Payout failed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Jackpot paid')
                    ->body(Money::format($txn->amount, $txn->currency).' to '.$txn->user->username)
                    ->send();
            });
    }

    /** Set the pool balance to an absolute figure, logging the delta. */
    public static function setBalance(string $name = 'setBalance'): Action
    {
        return Action::make($name)
            ->label('Set balance')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn () => auth()->user()?->isAdmin())
            ->schema([
                TextInput::make('balance')
                    ->numeric()->minValue(0)->required()
                    ->default(fn (Jackpot $record) => (float) $record->balance)
                    ->prefix(fn (Jackpot $record) => $record->shop?->currency?->value),
            ])
            ->action(function (array $data, Jackpot $record) {
                try {
                    $txn = app(Ledger::class)->setJackpotBalance($record, (float) $data['balance'], auth()->user());
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title($txn ? 'Jackpot balance updated' : 'No change')
                    ->send();
            });
    }

    /** Apply the same settings to several jackpots at once (legacy JPGController@global_update). */
    public static function bulkEdit(string $name = 'globalEdit'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Global edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn () => (bool) auth()->user()?->hasPermission('jpgame.edit'))
            ->schema([
                TextInput::make('contribution_percent')->numeric()->minValue(0)->maxValue(100)->label('Accrual %'),
                TextInput::make('payout_min')->numeric()->minValue(0),
                TextInput::make('payout_max')->numeric()->minValue(0),
                TextInput::make('seed_min')->numeric()->minValue(0),
                TextInput::make('seed_max')->numeric()->minValue(0),
                TextInput::make('balance')
                    ->numeric()->minValue(0)
                    ->helperText('Sets an absolute balance on every selected jackpot (logged per jackpot). Admin only.')
                    ->visible(fn () => auth()->user()?->isAdmin()),
            ])
            ->action(function (array $data, Collection $records) {
                $fields = array_filter(
                    ['contribution_percent', 'payout_min', 'payout_max', 'seed_min', 'seed_max'],
                    fn ($f) => filled($data[$f] ?? null),
                );

                $ledger = app(Ledger::class);
                $count = 0;

                foreach ($records as $jackpot) {
                    $patch = [];
                    foreach ($fields as $f) {
                        $patch[$f] = $data[$f];
                    }
                    if ($patch) {
                        $jackpot->update($patch);
                    }

                    if (auth()->user()->isAdmin() && filled($data['balance'] ?? null)) {
                        $ledger->setJackpotBalance($jackpot, (float) $data['balance'], auth()->user());
                    }

                    $count++;
                }

                Notification::make()->success()->title("{$count} jackpots updated")->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
