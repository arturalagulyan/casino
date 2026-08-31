<?php

namespace App\Enums;

/**
 * Which liquidity pool a game settles against
 * (legacy w_games.gamebank + w_game_bank columns).
 */
enum BankType: string
{
    case Slots = 'slots';
    case Little = 'little';
    case Table = 'table';
    case Bonus = 'bonus';
    case Fish = 'fish';

    /** Column name on the game_banks / user_banks tables. */
    public function column(): string
    {
        return $this === self::Table ? 'table_bank' : $this->value;
    }
}
