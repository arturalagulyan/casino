<?php

namespace App\Http\Controllers\Api;

use App\Models\ApiKey;
use App\Services\SeamlessWallet\GameLaunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Seamless-wallet game launch. ← legacy Web\Frontend\PlayersController.
 * `launch` is protected by the `api.key` middleware; `play` validates the token.
 */
class GameLaunchController extends Controller
{
    public function __construct(private GameLaunch $launcher) {}

    public function launch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'string', 'max:191'],
            'player_name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'balance' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:4'],
            'game' => ['required'],
        ]);

        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        try {
            $player = $this->launcher->resolvePlayer($apiKey, $data);
            $game = $this->launcher->resolveGame($apiKey, $data['game']);
            $token = $this->launcher->issueToken($player, $game);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'launch_url' => $this->launcher->launchUrl($token, $game->template->code),
            'token' => $token,
            'expires_in' => $this->launcher->ttl(),
            'player' => ['id' => $player->id, 'username' => $player->username, 'currency' => $player->currency?->value],
        ]);
    }

    public function play(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        try {
            ['user' => $user, 'game' => $game] = $this->launcher->verifyToken($request->query('token'));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        // Actual game HTML / websocket handshake is the spin-engine phase.
        return response()->json([
            'status' => 'ok',
            'note' => 'Game serving is not implemented yet — token is valid.',
            'user' => ['id' => $user->id, 'balance' => $user->wallet?->balance, 'currency' => $user->currency?->value],
            'game' => ['id' => $game->id, 'code' => $game->template->code, 'title' => $game->title ?? $game->template->title],
        ]);
    }
}
