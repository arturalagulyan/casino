<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\BooongoProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Booongo's game server as its game runner calls it: our page hands it
 * `server_url = /booongo/{session}/engine`, so a call is
 * `POST /booongo/{session}/engine?gsc=<command>` with a JSON body
 * (the runner's own log reports carry no `gsc`). See {@see BooongoProtocol}.
 */
class BooongoController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function engine(Request $request, string $token): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $command = (string) ($request->query('gsc') ?: ($body['command'] ?? ''));
        if ($command === '' || array_is_list($body)) {   // the runner's log / metrics posts
            return response()->json(['status' => ['code' => 'OK', 'message' => '']]);
        }
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::Booongo) {
            return response()->json(['command' => $command, 'request_id' => (string) ($body['request_id'] ?? ''),
                'status' => ['code' => 'SESSION_EXPIRED', 'type' => 'crit', 'message' => '']]);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        $reply = DB::transaction(function () use ($session, $command, $body) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return app(BooongoProtocol::class)->dispatch($context, $command, $body);
        });

        return response()->json($reply, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }
}
