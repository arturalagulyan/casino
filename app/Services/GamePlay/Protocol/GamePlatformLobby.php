<?php

namespace App\Services\GamePlay\Protocol;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\Shop;
use App\Services\GamePlay\GameConfig;

/**
 * Builds the `complex` game-list the EGT GamePlatform client expects in its
 * login response — from the shop's actual games on this protocol, not a static
 * catalogue. The client only needs its own entry (matched by gin) but hands the
 * whole map to its lobby.
 *
 * A game is "on this protocol" if its resolved config (category → template) says
 * so — there is no dedicated field to filter on.
 */
class GamePlatformLobby
{
    /**
     * @return array<string, list<array<string, mixed>>> gameType => [ entry ]
     */
    public function for(Shop $shop): array
    {
        $games = Game::query()
            ->where('shop_id', $shop->id)
            ->where('is_visible', true)
            ->with(['template', 'categories'])
            ->get();

        $list = [];
        $order = 1;

        foreach ($games as $game) {
            $config = new GameConfig($game->template, $game);
            if ($config->clientProtocol() !== ClientProtocol::GamePlatform) {
                continue;
            }

            $list[$this->gameType($config)] = [[
                'gameIdentificationNumber' => $this->gin($config),
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
            ]];
            $order++;
        }

        return $list;
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
