<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\ApolloProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Apollo Games' casino server, as our page's WebSocket shim calls it:
 * `POST /apollo/{session}/casino.<action>` with the frame's JSON body
 * (`{session}` is our game-session token). See {@see ApolloProtocol}.
 */
class ApolloController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function action(Request $request, string $token, string $action): JsonResponse
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::Apollo) {
            return response()->json(['category' => 'FATAL', 'code' => 'UNKNOWN_SESSION', 'title' => 'UNKNOWN_SESSION', 'detailed' => ''], 401);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $body = json_decode((string) $request->getContent(), true);

        [$status, $reply] = DB::transaction(function () use ($session, $action, $body) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return app(ApolloProtocol::class)->dispatch($context, $action, is_array($body) ? $body : []);
        });

        return response()->json($reply, $status, ['Cache-Control' => 'no-store', 'Authorization' => 'sessionToken'],
            JSON_UNESCAPED_SLASHES);
    }
}
