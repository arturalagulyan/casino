<?php

namespace App\Filament\Actions;

use App\Enums\TxnDirection;
use App\Models\Shop;
use App\Services\Ledger;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Top up / draw down a shop's credit float (legacy ShopController@balance). */
class AdjustShopCreditAction
{
    public static function make(string $name = 'adjustCredit'): Action
    {
        return Action::make($name)
            ->label('Adjust credit')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->visible(fn () => (bool) auth()->user()?->hasPermission('shops.manage'))
            ->schema([
                Radio::make('direction')
                    ->options([
                        TxnDirection::Credit->value => 'Add credit',
                        TxnDirection::Debit->value => 'Remove credit',
                    ])
                    ->default(TxnDirection::Credit->value)
                    ->inline()
                    ->required(),
                TextInput::make('amount')
                    ->numeric()->minValue(0.01)->required()
                    ->prefix(fn (Shop $record) => $record->currency->value)
                    ->helperText(fn (Shop $record) => new HtmlString(
                        'Current credit: <strong>'.Money::format($record->balance, $record->currency).'</strong>'
                    )),
                Textarea::make('note')->rows(2)->maxLength(255),
            ])
            ->action(function (array $data, Shop $record) {
                try {
                    $txn = app(Ledger::class)->adjustShopCredit(
                        $record,
                        (float) $data['amount'],
                        TxnDirection::from($data['direction']),
                        auth()->user(),
                        filled($data['note'] ?? null) ? ['note' => $data['note']] : [],
                    );
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Credit not changed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Shop credit updated')
                    ->body(($txn->direction === TxnDirection::Credit ? '+' : '−').' '.Money::format($txn->amount, $txn->currency))
                    ->send();
            });
    }
}
