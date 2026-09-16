<?php

namespace App\Services\GamePlay;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\Protocol\AmaticProtocol;
use App\Services\GamePlay\Protocol\GamePlatformProtocol;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * The one WebSocket entry point for every game that talks over a socket instead
 * of HTTP — a dumb bridge, like the legacy Slots.js.
 *
 * `game:socket` hands each raw frame here; we resolve the player + game from the
 * frame's `sessionId` (= a game_sessions.token, injected into the bundle's entry
 * HTML), work out which wire protocol that game speaks from its resolved config
 * (category → template), and hand off to that protocol handler.
 *
 * Each handler owns its own framing: the EGT "GamePlatform" handler emits
 * `:::`-prefixed JSON objects; the Amatic "amarent" handler emits bare packed
 * hex strings. {@see handle} returns the exact bytes to send — the socket
 * command sends them verbatim.
 *
 * A newer Amatic client generation (bundle path `gmsl/mpp/...`, e.g.
 * AdmiralNelsonNew) speaks the same `A/uNNN` command set but frames it as a
 * **bare, non-JSON string** with no `sessionId` field at all — session
 * identity is only carried once, positionally, in the `A/u25` init frame
 * (see {@see handleRawAmatic}), and every later frame on that connection is
 * assumed to belong to whichever session `A/u25` bound to it. That requires
 * per-connection state, unlike the stateless JSON path above — {@see $rawSessions}.
 * We only need a stable identifier per TCP connection for that, not the whole
 * Workerman connection object, so callers just pass `$conn->id`.
 */
class SocketServer
{
    /** Connection id → game_sessions.token, for raw (non-JSON) Amatic frames only. */
    private array $rawSessions = [];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Banker $banker,
        private readonly GamePlatformProtocol $gamePlatform,
        private readonly AmaticProtocol $amatic,
    ) {}

    /** @return list<string> ready-to-send wire frames */
    public function handle(int $connectionId, string $frame): array
    {
        $json = ltrim($frame);
        if (str_starts_with($json, ':::')) {
            $json = substr($json, 3);
        }

        $request = json_decode($json, true);

        if (! is_array($request)) {
            return $this->handleRawAmatic($connectionId, $frame);
        }

        $isAmatic = isset($request['gameData']);
        if (! $isAmatic && ! isset($request['command'])) {
            return [];
        }

        $session = $this->resolveSession((string) ($request['sessionId'] ?? ''));

        if (! $session) {
            return $isAmatic
                ? ['{"responseEvent":"error","responseType":"","serverResponse":"invalid login"}']
                : [':::'.$this->error($request, 'invalid login')];
        }

        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        return DB::transaction(function () use ($session, $request, $isAmatic) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);
            $protocol = $context->config()->clientProtocol();

            if ($protocol === ClientProtocol::Amatic || $isAmatic) {
                return $this->amatic->dispatch($context, $request);
            }

            return array_map(
                fn (array $m) => ':::'.json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->gamePlatform->dispatch($context, $request),
            );
        });
    }

    /** Drop this connection's bound raw-Amatic session (call from the socket's onClose). */
    public function unbind(int $connectionId): void
    {
        unset($this->rawSessions[$connectionId]);
    }

    /**
     * The newer Amatic client's frame, e.g. `A/u25,,<token>,Admiral,2_0_0,EN,EUR,…`
     * for init or bare `A/u251,<lines>,<betIndex>` for everything after — same
     * `A/uNNN` command names {@see AmaticProtocol} already understands, just
     * without a JSON envelope. `A/u25` carries our session token positionally
     * (index 2 — the client's `hash`/`user` fields ahead of it are always
     * empty since our launch flow seeds only the one field it reads for
     * freeplay/real-money identification, {@see GameAssetController}); every
     * other command carries no token at all and relies on the binding `A/u25`
     * made for this TCP connection.
     */
    private function handleRawAmatic(int $connectionId, string $frame): array
    {
        $parts = explode(',', $frame);
        $cmd = $parts[0];

        if (! str_starts_with($cmd, 'A/u')) {
            return []; // socket.io-style keepalive frames ("2::", "1::") etc — ignore
        }

        if ($cmd === 'A/u25') {
            $token = $parts[2] ?? '';
            $session = $this->resolveSession($token);
            if (! $session) {
                return ['{"responseEvent":"error","responseType":"","serverResponse":"invalid login"}'];
            }
            $this->rawSessions[$connectionId] = $token;
        } else {
            $token = $this->rawSessions[$connectionId] ?? null;
            $session = $token !== null ? $this->resolveSession($token) : null;
            if (! $session) {
                return [];
            }
        }

        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        return DB::transaction(function () use ($session, $frame) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return $this->amatic->dispatch($context, ['gameData' => $frame]);
        });
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
