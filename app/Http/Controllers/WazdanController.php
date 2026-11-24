<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\WazdanProtocol;
use App\Services\GamePlay\Wazdan\WazdanLiveClient;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wazdan's RGS, as our page's WebSocket shim calls it: `POST /wazdan/{session}`
 * with one client frame — the hex of its AES-encrypted JSON request — answered
 * with the hex of the encrypted reply ({@see WazdanLiveClient} for the cipher,
 * {@see WazdanProtocol} for the requests). `{session}` is our game-session token.
 */
class WazdanController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function frame(Request $request, string $token): Response
    {
        $session = $this->session($token);
        try {
            $msg = json_decode(WazdanLiveClient::decrypt((string) $request->getContent()), true);
        } catch (Throwable) {
            $msg = null;
        }
        if (! is_array($msg)) {
            Log::warning('Wazdan: an undecodable frame', ['body' => mb_strimwidth((string) $request->getContent(), 0, 200)]);

            return response('', 400);
        }
        if (! $session) {
            $reply = ['a' => 'xxxxxxxx', 'id' => $msg['id'] ?? 0, 's' => 'error', 'err' => 'session_expired', 'msg' => 'session_expired'];
        } else {
            $session->forceFill(['last_seen_at' => now()])->saveQuietly();
            $reply = DB::transaction(fn () => app(WazdanProtocol::class)->dispatch(
                new GameContext($session->user, $session->game, $this->ledger, $this->banker), $msg));
        }

        return response(WazdanLiveClient::encrypt(json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'), 200,
            ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    /**
     * The client's `help_url`: `?return_version=1` asks for the rules' version,
     * otherwise the rules page itself (desktop / mobile), as the import kept it.
     */
    public function help(Request $request, string $token): Response
    {
        $session = $this->session($token) ?? abort(404);
        $template = $session->game->template;
        if ($request->query('return_version')) {
            return response(json_encode(['helpVersion' => $template->bonus_config['wazdan']['help_version'] ?? '4.3.11.6']) ?: '{}', 200,
                ['Content-Type' => 'application/json']);
        }
        $bundle = $template->activeBundle ?? abort(404);
        $type = $request->query('type') === 'mobile' ? 'mobile' : 'desktop';
        $rel = $bundle->filePath("help/{$type}.html") ?? $bundle->filePath('help/desktop.html') ?? abort(404);

        return response($bundle->disk()->get($rel), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function session(string $token): ?GameSession
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();

        return $session && $session->game
            && (new GameConfig($session->game->template, $session->game))->clientProtocol() === ClientProtocol::Wazdan ? $session : null;
    }
}
