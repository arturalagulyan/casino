<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\PragmaticPlayProtocol;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Pragmatic Play's game service as its client calls it. Our page hands it
 * `gameService = /pragmatic/{session}/gameService`; the client derives its
 * other endpoints from that folder (`saveSettings.do`, `reloadBalance.do`,
 * `promo/active/`, …). See {@see PragmaticPlayProtocol}.
 */
class PragmaticPlayController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function endpoint(Request $request, string $token, string $endpoint = ''): Response
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::PragmaticPlay) {
            return $this->text('unlogged');
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $params = array_map('strval', array_filter($request->request->all(), 'is_scalar'));

        return match (true) {
            $endpoint === 'gameService' => $this->text(DB::transaction(function () use ($session, $params) {
                $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

                return app(PragmaticPlayProtocol::class)->dispatch($context, $params);
            })),
            $endpoint === 'saveSettings.do' => $this->settings($session, $params),
            $endpoint === 'reloadBalance.do' => $this->text(http_build_query(['balance_bonus' => '0.00',
                'balance' => number_format((float) $session->user->wallet?->balance, 2, '.', ''),
                'balance_cash' => number_format((float) $session->user->wallet?->balance, 2, '.', ''),
                'stime' => (int) floor(microtime(true) * 1000)])),
            str_starts_with($endpoint, 'promo/') => response('{"error":0,"description":"OK"}', 200, ['Content-Type' => 'application/json']),
            default => $this->text(''),   // client logs, stats, regulation pings
        };
    }

    /**
     * The client's settings (sound, intro screen, …): `method=load&id=<game|vsCommon>`
     * reads them, `id=…&settings=…` stores them — per player and id.
     *
     * @param  array<string, string>  $params
     */
    private function settings(GameSession $session, array $params): Response
    {
        $key = 'pragmatic:settings:'.$session->user_id.':'.preg_replace('/[^A-Za-z0-9_]/', '', (string) ($params['id'] ?? ''));
        if (($params['method'] ?? '') === 'load') {
            return $this->text((string) cache()->get($key, ''));
        }
        if (isset($params['settings'])) {
            cache()->forever($key, (string) $params['settings']);
        }

        return $this->text('');
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain;charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
