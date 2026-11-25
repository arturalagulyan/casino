<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\PlaytechNgmProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Playtech's casino platform, as our page's WebSocket shim calls it:
 * `POST /playtech/{session}` with one client message (the JSON of a
 * Socket.IO `3:::` frame), answered with the list of messages to hand back
 * ({@see PlaytechNgmProtocol}). `{session}` is our game-session token.
 */
class PlaytechNgmController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function message(Request $request, string $token): JsonResponse
    {
        $msg = json_decode((string) $request->getContent(), true);
        if (! is_array($msg)) {
            Log::warning('Playtech: an undecodable message', ['body' => mb_strimwidth((string) $request->getContent(), 0, 200)]);

            return response()->json([], 400);
        }
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::PlaytechNgm) {
            // the platform's "session closed" — the client shows its own error
            return response()->json([['data' => ['errorCode' => 6, 'errorText' => 'session expired'], 'ID' => 10003]]);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $out = DB::transaction(fn () => app(PlaytechNgmProtocol::class)->dispatch(
            new GameContext($session->user, $session->game, $this->ledger, $this->banker), $msg));

        return response()->json($out, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
