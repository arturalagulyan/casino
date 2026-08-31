<?php

namespace App\Models;

use App\Enums\BankType;
use App\Enums\Device;
use App\Enums\GameEngine;
use App\Enums\ScaleMode;
use App\Enums\ViewState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The master game catalogue — one row per installed game package.
 * ← legacy w_games (shop_id = 0) + w_game_path.
 */
class GameTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'engine' => GameEngine::class,
            'device' => Device::class,
            'bank_type' => BankType::class,
            'scale_mode' => ScaleMode::class,
            'view_state' => ViewState::class,
            'default_bet_options' => 'array',
            'default_lines_config' => 'array',
            'default_jackpot_chances' => 'array',
            'default_advanced' => 'array',
            'default_denomination' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'template_id');
    }
}
