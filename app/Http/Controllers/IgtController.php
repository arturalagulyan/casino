<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\IgtProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IGT's RGS game server as its client calls it: the page hands it
 * `server=/igt/{session}/rgs?`, so a call is
 * `GET|POST /igt/{session}/rgs?/<action>&<params>` — the action is
 * the raw query string's first part. See {@see IgtProtocol}.
 */
class IgtController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function engine(Request $request, string $token): JsonResponse
    {
        $query = (string) parse_url($request->getRequestUri(), PHP_URL_QUERY);
        $action = trim(strtok(rawurldecode($query), '&') ?: '', '/');
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::Igt) {
            return response()->json(['Exception' => ['Id' => '1005', 'Message' => 'Session expired']]);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $body = json_decode((string) $request->getContent(), true);

        $reply = DB::transaction(function () use ($session, $action, $body) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return app(IgtProtocol::class)->dispatch($context, $action, is_array($body) ? $body : []);
        });

        return response()->json($reply, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }
}
