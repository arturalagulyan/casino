<?php

namespace App\Http\Controllers\Api;

use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The universal game-play endpoint. The front-end bundle POSTs every command
 * (init / bet / state / …) here with its game-session token; we resolve the
 * player + game, build a GameContext, and hand off to the game's server class.
 *
 * ← replaces the legacy per-game `POST /game/{game}/server` (runServer) route.
 */
class GameServerController extends Controller
{
    public function __construct(
        private GameRegistry $registry,
        private Ledger $ledger,
        private Banker $banker,
    ) {}

    public function handle(Request $request, string $code): JsonResponse
    {
        $token = $request->bearerToken()
            ?? $request->input('session')
            ?? $request->header('X-Game-Session');

        $session = $token
            ? GameSession::where('token', $token)->where('is_active', true)->with('user.wallet', 'game.template', 'game.shop')->first()
            : null;

        if (! $session || ! $session->game || $session->game->template->code !== $code) {
            return response()->json(['error' => 'Invalid or expired game session.'], 403);
        }

        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

        try {
            $server = $this->registry->for($session->game);
            $result = $server->handle($context, $request->all() + ['command' => $request->input('command', 'init')]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => $e->getMessage(),
                'balance' => round($context->balance(), 4),
            ], 422);
        }

        return response()->json($result);
    }
}
