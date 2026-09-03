<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\Game;
use App\Models\Shop;
use App\Services\GamePlay\GameConfig;

/**
 * Builds the `complex` game-list the EGT GamePlatform client expects in its
 * login response.
 *
 * **Single-game launch only.** Every launch opens one specific game in an iframe;
 * the client boots straight into it when `complex` holds exactly that one entry.
 * As soon as `complex` has 2+ games the client renders its multi-game *lobby*
 * (and 404s on every `<GameType>JSlot_idle.png` thumbnail) instead of the game —
 * which is the "opens a game list" bug. So `for()` returns just the session game.
 */
class GamePlatformLobby
{
    /**
     * @param  Game|null  $current  the session's own game
     * @param  int|null  $currentGin  gin the client's bundle connected with — must
     *                                equal the entry's gin (GameAssetController
     *                                rewrites the bundle to `gin($config)`)
     * @return array<string, list<array<string, mixed>>> gameType => [ entry ]
     */
    public function for(Shop $shop, ?Game $current = null, ?int $currentGin = null): array
    {
        if (! $current) {
            return [];
        }

        $config = new GameConfig($current->template, $current);

        return [
            $this->gameType($config) => [
                $this->entry($current, $currentGin ?: $this->gin($config), 1),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Game $game, int $gin, int $order): array
    {
        return [
            'gameIdentificationNumber' => $gin,
            'recovery' => 'norecovery',
            'gameName' => $game->title ?? $game->template->title,
            'featured' => false,
            'mlmJackpot' => true,
            'totalBet' => 0,
            'groups' => [
                ['order' => $order, 'name' => 'all'],
                ['order' => $order, 'name' => 'myGames'],
            ],
            'jackpotGameType' => 'MLMJackpot',
        ];
    }

    /** Client key for a game — `layout.egt.game_type`, else derived from the code. */
    public function gameType(GameConfig $config): string
    {
        return (string) data_get($config->layout(), 'egt.game_type')
            ?: str($config->template->code)->replace('EGT', '')->finish('JSlot')->toString();
    }

    /** Stable numeric id the client matches on — `layout.egt.gin`, else from the template id. */
    public function gin(GameConfig $config): int
    {
        return (int) (data_get($config->layout(), 'egt.gin') ?: 100000 + $config->template->id);
    }
}
