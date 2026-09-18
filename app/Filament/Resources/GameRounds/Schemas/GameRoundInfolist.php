<?php

namespace App\Filament\Resources\GameRounds\Schemas;

use App\Enums\Currency;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GameRoundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (string $field, string $label) => TextEntry::make($field)
            ->label($label)
            ->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency));

        return $schema
            ->components([
                TextEntry::make('shop.name')
                    ->label('Shop'),
                TextEntry::make('user.id')
                    ->label('User'),
                TextEntry::make('game.title')
                    ->label('Game')
                    ->placeholder('-'),
                TextEntry::make('game_code'),
                TextEntry::make('currency')
                    ->badge()
                    ->html()
                    ->formatStateUsing(fn ($state) => Currency::chipFor($state)),
                $money('bet', 'Bet'),
                $money('win', 'Win'),
                $money('balance_after', 'Balance'),
                $money('stake_to_bank', 'Game in'),
                $money('stake_to_jackpot', 'Jackpot in'),
                $money('stake_to_profit', 'Profit')
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
                TextEntry::make('denomination')
                    ->numeric(),
                TextEntry::make('status')
                    ->numeric(),
                TextEntry::make('played_at')
                    ->dateTime(),
            ]);
    }
}
