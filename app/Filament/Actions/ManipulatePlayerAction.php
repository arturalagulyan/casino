<?php

namespace App\Filament\Actions;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Enums\TxnDirection;
use App\Models\User;
use App\Models\UserBank;
use App\Services\Ledger;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Per-player win/loss manipulation via the player's own liquidity pool
 * (user_banks). When "manipulation active" is on, that player's spins settle
 * against this bank instead of the shop game bank: topping a pool up funds a
 * win run, zeroing / driving it negative freezes wins. Every money move is an
 * audited transactions row (source = user_bank), exactly like the shop bank.
 */
class ManipulatePlayerAction
{
    public static function make(string $name = 'manipulatePlayer'): Action
    {
        return Action::make($name)
            ->label('Manipulate player')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('danger')
            ->visible(fn (User $record) => $record->isPlayer()
                && (bool) auth()->user()?->hasPermission('users.manipulate'))
            ->schema([
                Toggle::make('is_active')
                    ->label('Manipulation active')
                    ->default(fn (User $record) => (bool) self::bankFor($record)?->is_active)
                    ->helperText('While on, this player\'s spins settle against their own bank below — not the shop game bank.'),
                TextInput::make('temp_rtp')
                    ->label('Individual RTP %')
                    ->numeric()->minValue(0)->maxValue(100)
                    ->default(fn (User $record) => self::bankFor($record)?->temp_rtp)
                    ->helperText('Optional. Overrides the shop / game RTP for this player only.'),
                Select::make('pool')
                    ->options(collect(BankType::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)]))
                    ->default(BankType::Slots->value)
                    ->helperText(fn (User $record) => new HtmlString(self::poolSummary($record)))
                    ->required(),
                Radio::make('direction')
                    ->options([
                        TxnDirection::Credit->value => 'Add to pool (fund wins)',
                        TxnDirection::Debit->value => 'Take from pool (suppress wins)',
                    ])
                    ->default(TxnDirection::Credit->value)
                    ->inline()
                    ->required(),
                TextInput::make('amount')
                    ->numeric()->minValue(0)
                    ->helperText('Leave blank to only change the toggle / RTP.')
                    ->prefix(fn (User $record) => self::currency($record)->value),
            ])
            ->action(function (array $data, User $record) {
                $bank = self::ensureBank($record);

                $bank->update([
                    'is_active' => (bool) ($data['is_active'] ?? false),
                    'temp_rtp' => filled($data['temp_rtp'] ?? null) ? (float) $data['temp_rtp'] : null,
                ]);

                if (filled($data['amount'] ?? null) && (float) $data['amount'] > 0) {
                    try {
                        app(Ledger::class)->adjustUserBankPool(
                            $bank,
                            BankType::from($data['pool']),
                            (float) $data['amount'],
                            TxnDirection::from($data['direction']),
                            auth()->user(),
                        );
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Pool not changed')->body($e->getMessage())->send();

                        return;
                    }
                }

                $record->refresh();

                Notification::make()->success()
                    ->title($bank->is_active ? 'Manipulation active' : 'Manipulation off')
                    ->body(strip_tags(self::poolSummary($record)))
                    ->send();
            });
    }

    private static function currency(User $record): Currency
    {
        return $record->currency ?? $record->wallet->currency ?? Currency::default();
    }

    private static function bankFor(User $record): ?UserBank
    {
        return $record->userBankFor(self::currency($record));
    }

    private static function ensureBank(User $record): UserBank
    {
        /** @var UserBank */
        return $record->banks()->firstOrCreate(
            ['currency' => self::currency($record)->value],
            ['shop_id' => $record->shop_id],
        );
    }

    private static function poolSummary(User $record): string
    {
        $bank = self::bankFor($record);
        $currency = self::currency($record);

        if (! $bank) {
            return 'No manipulation bank yet — saving will create one.';
        }

        $parts = [];
        foreach (BankType::cases() as $type) {
            $parts[] = ucfirst($type->value).': <strong>'
                .Money::format($bank->amountFor($type), $currency).'</strong>';
        }

        return implode(' &nbsp;·&nbsp; ', $parts);
    }
}
