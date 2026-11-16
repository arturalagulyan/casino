<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\NetEntProtocol;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * NetEnt's game servlet, as its HTML5 client calls it:
 * `GET /netent/{session}/servlet/CasinoGameServlet;jsession=<session>?action=…`
 * (`{session}` is our game-session token — the page hands the client
 * `server=/netent/{session}/`). See {@see NetEntProtocol}.
 */
class NetEntController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function servlet(Request $request, string $token, string $servlet = ''): Response
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();
        if (! $session || ! $session->game
            || (new GameConfig($session->game->template, $session->game))->clientProtocol() !== ClientProtocol::NetEnt) {
            return response('clientaction='.rawurlencode((string) $request->query('action')).'&errorcode=11&errormessage=Session%20expired', 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        // the raw query: PHP's own parsing mangles NetEnt's dotted keys (bet.betlevel → bet_betlevel)
        $query = [];
        foreach (explode('&', (string) parse_url($request->getRequestUri(), PHP_URL_QUERY)) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($k !== '') {
                $query[urldecode($k)] = urldecode($v);
            }
        }

        $reply = DB::transaction(function () use ($session, $query, $servlet) {
            $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

            return str_starts_with($servlet, 'InfoServlet')
                ? app(NetEntProtocol::class)->info($context, $query)
                : app(NetEntProtocol::class)->dispatch($context, $query);
        });

        return response($reply)->header('Content-Type', 'text/plain; charset=utf-8')->header('Cache-Control', 'no-store');
    }
}
