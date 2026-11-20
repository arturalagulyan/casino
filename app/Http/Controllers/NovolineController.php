<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\NovolineProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Greentube's game server as our page's WebSocket shim calls it: one client
 * frame per `POST /novoline/{session}/ws` (raw body), the frames the server
 * answers as a JSON list. See {@see NovolineProtocol}.
 */
class NovolineController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function frame(Request $request, string $token): JsonResponse
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::Novoline) {
            return response()->json(['closed' => true], 410);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $frame = (string) $request->getContent();

        $frames = DB::transaction(function () use ($session, $frame) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return app(NovolineProtocol::class)->dispatch($context, $frame);
        });

        return response()->json($frames, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }
}
