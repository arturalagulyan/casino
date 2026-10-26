<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\PlayNGoProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * What a Play'n GO ContainerLauncher page talks to, under
 * `/playngo/{session}/` (its "CoreWeb" base, which our launcher shell sets):
 * the game lookup and the two configuration documents it reads before
 * loading the game, and `server` — the line-protocol endpoint every packet
 * is posted to ({@see PlayNGoProtocol}).
 *
 * The configuration is Play'n GO's own, captured at import, with the parts
 * that would reach Play'n GO rewired: resources from our mirror, packets over
 * HTTP to us, no external modules (history, promotions).
 */
class PlayNGoController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function game(string $token, string $gid): JsonResponse
    {
        $c = $this->playngo($this->session($token));

        return response()->json(['gameId' => $c['game_id'], 'gId' => $c['gid'], 'name' => $c['name'], 'gameType' => $c['game_type'],
            'limitsValues' => ['game.rtp', 'game.denominations']]);
    }

    public function prelaunch(string $token): JsonResponse
    {
        $session = $this->session($token);
        $pre = (array) ($this->playngo($session)['prelaunch'] ?? []);
        $pre['Server'] = url("/playngo/{$token}/server");
        $pre['ServerMessages'] = null;

        return response()->json($pre);
    }

    public function configuration(Request $request, string $token): JsonResponse
    {
        $session = $this->session($token);
        $c = $this->playngo($session);
        $channel = $request->query('channel') === 'mobile' ? 'mobile' : 'desktop';
        $cfg = (array) ($c['configuration'][$channel] ?? $c['configuration']['desktop'] ?? []);
        $code = $session->game->template->code;

        $cfg['resourceRoot'] = url("/games/{$code}/cdn/".trim((string) $c['build'], '/')).'/';
        $cfg['defaultProtocol'] = 'http';
        $cfg['availableModules'] = [];
        $cfg['winValuesUrl'] = '';
        $cfg['minBetValuesUrl'] = '';
        $cfg['helpUrl'] = '';
        $cfg['disableHelp'] = true;
        $cfg['disableHistory'] = true;
        $cfg['hideHistory'] = true;
        $cfg['playForRealUrl'] = '';
        $cfg['lobbyUrl'] = '';
        $cfg['cashierUrl'] = '';
        $cfg['neverGamble'] = true;
        $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);
        $values = app(PlayNGoProtocol::class)->coinValues($context);
        $cfg['denom'] = (string) ($values[intdiv(count($values), 2)] ?? 10);

        return response()->json($cfg);
    }

    public function server(Request $request, string $token): Response
    {
        $session = $this->session($token);
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        $body = (string) $request->getContent();

        $reply = DB::transaction(function () use ($session, $body) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return app(PlayNGoProtocol::class)->dispatch($context, $body);
        });

        return response($reply)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    private function session(string $token): GameSession
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::PlayNGo) {
            abort(403, 'Invalid or expired game session.');
        }

        return $session;
    }

    /** @return array<string, mixed> the template's captured Play'n GO setup */
    private function playngo(GameSession $session): array
    {
        return (array) ((new GameConfig($session->game->template, $session->game))->bonusConfig()['playngo'] ?? []);
    }
}
