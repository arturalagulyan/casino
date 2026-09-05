<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\GameRegistry;
use App\Services\GamePlay\Protocol\PragmaticProtocol;
use App\Services\GamePlay\Protocol\SlotEventProtocol;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

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

    public function handle(Request $request, string $code): Response
    {
        $token = $request->bearerToken()
            ?? $request->input('session')
            ?? $request->header('X-Game-Session')
            ?? $request->query('sessionId');   // legacy slotEvent bundles

        $session = $token
            ? GameSession::where('token', $token)->where('is_active', true)->with('user.wallet', 'game.template', 'game.shop')->first()
            : null;

        if (! $session || ! $session->game || $session->game->template->code !== $code) {
            return response()->json(['error' => 'Invalid or expired game session.'], 403);
        }

        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);
        $protocol = (new GameConfig($session->game->template, $session->game))->clientProtocol();

        try {
            // Legacy `slotEvent` games return their own `{responseEvent,…}` frame.
            if ($protocol === ClientProtocol::SlotEvent) {
                return response()->json(app(SlotEventProtocol::class)->dispatch($context, $request->all()));
            }

            // Legacy Pragmatic games speak a raw `3:::{…}------3:::{…}` text
            // body (a faked Socket.IO v0.9 transport) — never JSON-wrapped.
            if ($protocol === ClientProtocol::Pragmatic) {
                return response(app(PragmaticProtocol::class)->dispatch($context, $request->all()))
                    ->header('Content-Type', 'text/plain');
            }

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
