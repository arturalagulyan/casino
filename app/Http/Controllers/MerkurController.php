<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\MerkurProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Merkur's (Edict) WebEngine.php, as our page's launcher calls it:
 * `GET|POST /merkur/{session}/engine?<path>` — the path is the raw query
 * string, as on Edict's own server (`?req=whow-fm&type=gateway&/players/…`,
 * `?/services/gaming/5/v2/gateway/…/commands/commands`). `{session}` is our
 * game-session token. See {@see MerkurProtocol}.
 */
class MerkurController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function engine(Request $request, string $token): JsonResponse
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template.activeBundle', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::Merkur) {
            return response()->json(['errorResponseDTO' => ['systemErrorCode' => 0, 'returnCode' => 401, 'returnMsg' => 'UNKNOWN_SESSION']], 401);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $body = json_decode((string) $request->getContent(), true);
        $template = $session->game->template;
        $folder = (string) ($template->bonus_config['merkur']['game_key'] ?? '');
        $root = url("/games/{$template->code}~v".($template->activeBundle->version ?? 1)."/{$folder}/html");

        [$status, $reply] = DB::transaction(function () use ($session, $request, $body, $root) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return app(MerkurProtocol::class)->dispatch($context, (string) $request->server('QUERY_STRING', ''), is_array($body) ? $body : [],
                ['game_root' => $root, 'launch' => "{$root}/index-mobile.html"]);
        });

        return response()->json($reply, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }
}
