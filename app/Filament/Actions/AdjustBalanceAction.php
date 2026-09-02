<?php

namespace App\Filament\Actions;

use App\Enums\TxnDirection;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Cash in / out on a user's balance, routed through the Ledger so every move
 * writes an audited transactions row (legacy UsersController@updateBalance).
 */
class AdjustBalanceAction
{
    public static function make(string $name = 'adjustBalance'): Action
    {
        return Action::make($name)
            ->label('Adjust balance')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->visible(fn () => (bool) auth()->user()?->hasPermission('users.manage'))
            ->schema([
                Radio::make('direction')
                    ->options([
                        TxnDirection::Credit->value => 'Cash in (add)',
                        TxnDirection::Debit->value => 'Cash out (remove)',
                    ])
                    ->default(TxnDirection::Credit->value)
                    ->inline()
                    ->required(),
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->prefix(fn (User $record) => $record->wallet->currency->value)
                    ->helperText(fn (User $record) => new HtmlString(
                        'Current balance: <strong>'.Money::format(
                            $record->wallet->balance,
                            $record->wallet->currency,
                        ).'</strong>'
                    )),
                Textarea::make('note')->rows(2)->maxLength(255),
            ])
            ->action(function (array $data, User $record) {
                $ledger = app(Ledger::class);
                $direction = TxnDirection::from($data['direction']);
                $actor = auth()->user();
                $context = filled($data['note'] ?? null) ? ['note' => $data['note']] : [];

                try {
                    $isStaff = $record->hasRole(['agent', 'distributor', 'manager', 'cashier']);

                    $txn = $isStaff
                        ? $ledger->adjustStaff($record, (float) $data['amount'], $direction, $actor, $context)
                        : $ledger->adjustPlayer($record, (float) $data['amount'], $direction, $actor, context: $context);
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Balance not changed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Balance updated')
                    ->body(($direction === TxnDirection::Credit ? '+' : '−').' '.Money::format($txn->amount, $txn->currency))
                    ->send();
            });
    }
}
