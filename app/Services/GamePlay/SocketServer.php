<?php

namespace App\Services\GamePlay;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\Protocol\GamePlatformProtocol;
use App\Services\GamePlay\Protocol\GameProtocol;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * The one WebSocket entry point for every game that talks over a socket instead
 * of HTTP — a dumb bridge, like the legacy Slots.js.
 *
 * `game:socket` hands each raw `:::`-prefixed frame here; we resolve the player +
 * game from the frame's `sessionId` (= a game_sessions.token, injected into the
 * bundle's entry HTML), work out which wire protocol that game speaks from its
 * resolved config (category → template), and hand off to that protocol handler.
 *
 * The protocol handlers under {@see Protocol} are the only
 * client-shaped code — a small fixed set, one per legacy wire format, each shared
 * by every game that speaks it. They all sit on the same {@see Engine\SlotEngine}.
 */
class SocketServer
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Banker $banker,
        private readonly GamePlatformProtocol $gamePlatform,
    ) {}

    /** @return list<string> JSON messages to frame back to the client */
    public function handle(string $frame): array
    {
        $json = ltrim($frame);
        if (str_starts_with($json, ':::')) {
            $json = substr($json, 3);
        }

        $request = json_decode($json, true);

        // socket.io-style keepalive frames ("2::", "1::") — ignore
        if (! is_array($request) || ! isset($request['command'])) {
            return [];
        }

        $session = $this->resolveSession((string) ($request['sessionId'] ?? ''));

        if (! $session) {
            return [$this->error($request, 'invalid login')];
        }

        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        $messages = DB::transaction(function () use ($session, $request) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return $this->protocolFor($context->config()->clientProtocol())->dispatch($context, $request);
        });

        return array_map(
            fn (array $m) => json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $messages,
        );
    }

    private function protocolFor(ClientProtocol $protocol): GameProtocol
    {
        return match ($protocol) {
            ClientProtocol::GamePlatform => $this->gamePlatform,
            default => $this->gamePlatform,   // the only socket protocol so far
        };
    }

    private function resolveSession(string $token): ?GameSession
    {
        if ($token === '') {
            return null;
        }

        $session = GameSession::query()
            ->where('token', $token)
            ->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop', 'game.categories'])
            ->first();

        if (! $session || ! $session->game) {
            return null;
        }

        return (new GameConfig($session->game->template, $session->game))
            ->clientProtocol()->usesWebSocket()
            ? $session
            : null;
    }

    private function error(array $request, string $message): string
    {
        return json_encode([
            'responseEvent' => 'error',
            'responseType' => '',
            'serverResponse' => $message,
            'messageId' => (string) ($request['messageId'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
    }
}
