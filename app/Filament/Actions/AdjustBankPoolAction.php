<?php

namespace App\Filament\Actions;

use App\Enums\BankType;
use App\Enums\TxnDirection;
use App\Models\GameBank;
use App\Services\Ledger;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Move money into/out of one liquidity pool (legacy DashboardController@banks_update). */
class AdjustBankPoolAction
{
    public static function make(string $name = 'adjustPool'): Action
    {
        return Action::make($name)
            ->label('Adjust pool')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->visible(fn () => auth()->user()?->isAdmin())
            ->schema([
                Select::make('pool')
                    ->options(collect(BankType::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)]))
                    ->default(BankType::Slots->value)
                    ->required(),
                Radio::make('direction')
                    ->options([
                        TxnDirection::Credit->value => 'Add to pool',
                        TxnDirection::Debit->value => 'Take from pool',
                    ])
                    ->default(TxnDirection::Credit->value)
                    ->inline()
                    ->required(),
                TextInput::make('amount')
                    ->numeric()->minValue(0.01)->required()
                    ->prefix(fn (GameBank $record) => $record->currency->value),
            ])
            ->action(function (array $data, GameBank $record) {
                try {
                    $txn = app(Ledger::class)->adjustBankPool(
                        $record,
                        BankType::from($data['pool']),
                        (float) $data['amount'],
                        TxnDirection::from($data['direction']),
                        auth()->user(),
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Pool not changed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Bank pool updated')
                    ->body(ucfirst($data['pool']).': '.($txn->direction === TxnDirection::Credit ? '+' : '−').' '.Money::format($txn->amount, $txn->currency))
                    ->send();
            });
    }
}
