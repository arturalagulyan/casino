<?php

namespace App\Http\Controllers;

use App\Enums\ClientProtocol;
use App\Models\GameSession;
use App\Services\Banker;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\Protocol\MicrogamingProtocol;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Microgaming's Titan shell as it calls the casino side. Our page points every
 * session-host URL at /microgaming/{session}: the shell's config
 * (`config.js`, Betboy's `mg/config.php`) and its API root
 * (`engine?<path>` for Betboy's `skvCore/WebEngine.php?<path>`):
 *
 *   /accounts/login/{demo|token|real|practice}   the player's account and user tokens
 *   /accounts/acquireLaunchTokens, refreshUserToken, negotiateSession, logout
 *   /games/incompleteGames
 *   /games/module/<mid>/client/<cid>/play        game packets ({@see MicrogamingProtocol})
 */
class MicrogamingController extends Controller
{
    public function __construct(private readonly Ledger $ledger, private readonly Banker $banker) {}

    public function engine(Request $request, string $token): JsonResponse|Response
    {
        $session = $this->session($token);
        if (! $session) {
            return response()->json(['code' => 6, 'message' => 'Session expired'], 401);
        }
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        // the path is the query string: engine?/games/module/1/client/2/play[?fields=…] — the classic
        // shell puts a request counter first (engine?2233/accounts/login/token) and posts its game
        // packets to engine?a=<time>
        $path = '/'.ltrim((string) preg_replace('#^\d*#', '', (string) strtok(rawurldecode((string) $request->server('QUERY_STRING')), '?&')), '/');

        // the older mobile shell (Gods of Giza) posts bare XML packets (engine?&a=<time>) and reads bare
        // XML back: its AdvSlot packets are the game's; the rest (GenericAuthLogin, MachineInfo,
        // PlayerInformationRequest, VP_GETNUMMESSAGES) get what Betboy answered at import
        $raw = ltrim((string) $request->getContent());
        if (str_starts_with($raw, '<Pkt')) {
            $verb = preg_match('/<Id\b[^>]*\bverb=[\'"]([A-Za-z_]+)[\'"]/', $raw, $v) ? $v[1] : '';
            if ($verb === 'AdvSlot') {
                $reply = DB::transaction(fn () => app(MicrogamingProtocol::class)
                    ->dispatch(new GameContext($session->user, $session->game, $this->ledger, $this->banker), $raw));
            } else {
                $recorded = $this->recorded($session, "xml:{$verb}");
                $reply = $recorded !== null ? (string) $recorded->getContent() : "<Pkt><Id verb=\"{$verb}\"/><Response/></Pkt>";
            }

            return response($reply, 200, ['Content-Type' => 'text/xml; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if (preg_match('#^/games/module/\d+/client/\d+/play$#', $path) || $request->json('packet.payload') !== null) {
            $packet = (string) ($request->json('packet.payload') ?? '');
            $reply = DB::transaction(function () use ($session, $packet) {
                $context = new GameContext($session->user, $session->game, $this->ledger, $this->banker);

                return app(MicrogamingProtocol::class)->dispatch($context, $packet);
            });
            $session->refresh();

            return response()->json(['context' => ['balances' => ['totalInAccountCurrency' => $this->balance($session)]],
                'packet' => ['payload' => $reply, 'packetType' => 7, 'isBase64Encoded' => false]], 200, ['Cache-Control' => 'no-store']);
        }

        return match (true) {
            str_starts_with($path, '/accounts/login'), $path === '/accounts/negotiateSession', $path === '/accounts/refreshUserToken'
                => response()->json(['account' => $this->account($session), 'tokens' => $this->tokens($session)]),
            $path === '/accounts' => response()->json($this->account($session)),
            $path === '/accounts/acquireLaunchTokens' => response()->json(['launchTokens' => $this->tokens($session)['launchTokens']]),
            $path === '/games/incompleteGames' => response()->json(['incompleteGames' => []]),
            default => $this->recorded($session, "engine:{$path}") ?? response()->json((object) []),   // authenticate, sessions, devicedetails, telemetry, …
        };
    }

    /**
     * Anything else the shell asks the session host for (`mg/commonStatic/clients/`,
     * `…/webapplications`, …): what Betboy answered when the game was imported —
     * a redirect to the static host goes to this bundle's copy.
     */
    public function page(string $token, string $path): Response|JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $session = $this->session($token);
        if (! $session) {
            abort(404);
        }

        return $this->recorded($session, rtrim((string) preg_replace('#\.php$#', '', $path), '/'))
            ?? (preg_match('#(?:^|/)(commonStatic/.+)$#', $path, $m) && ($static = $this->staticBase($session)) !== ''
                ? redirect($static.$m[1]) : response('', 404));
    }

    /** A recorded session-host reply, its URLs ours. */
    private function recorded(GameSession $session, string $key): ?Response
    {
        $replies = (array) ($this->mg($session)['session'] ?? []);
        $reply = $replies[$key] ?? $replies[$key.'/'] ?? null;   // imports before keys lost their trailing slash
        if (! is_array($reply)) {
            return null;
        }
        $fill = fn (string $text) => str_replace(['{{MG_SESSION}}', '{{MG_STATIC}}'], [url("/microgaming/{$session->token}"), $this->staticBase($session)], $text);
        if ((int) $reply['status'] >= 300 && (int) $reply['status'] < 400 && $reply['location'] !== '') {
            return response('', 302, ['Location' => $fill((string) $reply['location'])]);
        }

        return response($fill((string) $reply['body']), (int) $reply['status'] ?: 200,
            ['Content-Type' => (string) ($reply['type'] ?: 'text/plain'), 'Cache-Control' => 'no-store']);
    }

    private function staticBase(GameSession $session): string
    {
        $template = $session->game->template;

        return $template->activeBundle ? url("/games/{$template->code}~v{$template->activeBundle->version}").'/' : '';
    }

    /** @return array<string, mixed> */
    private function mg(GameSession $session): array
    {
        return (array) ((new GameConfig($session->game->template, $session->game))->bonusConfig()['microgaming'] ?? []);
    }

    /** The shell's config (Betboy's `mg/config.php`): the recorded one, its session URLs ours. */
    public function config(string $token): Response
    {
        $session = $this->session($token);
        $mg = $session ? (array) ((new GameConfig($session->game->template, $session->game))->bonusConfig()['microgaming'] ?? []) : [];
        $template = $session?->game->template;
        $static = $template && $template->activeBundle ? url("/games/{$template->code}~v{$template->activeBundle->version}").'/' : '';
        $js = str_replace(['{{MG_SESSION}}', '{{MG_STATIC}}'], [url("/microgaming/{$token}"), $static],
            (string) (($mg['titan_config'] ?? '') ?: ($mg['session']['mg/config']['body'] ?? 'window.titanConfig={};')));

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store']);
    }

    private function session(string $token): ?GameSession
    {
        $session = GameSession::query()->where('token', $token)->where('is_active', true)
            ->with(['user.wallet', 'game.template', 'game.shop'])->first();

        return $session && $session->game
            && (new GameConfig($session->game->template, $session->game))->clientProtocol() === ClientProtocol::Microgaming ? $session : null;
    }

    /** @return array<string, mixed> the recorded demo account, with our player's balance and currency */
    private function account(GameSession $session): array
    {
        $mg = (array) ((new GameConfig($session->game->template, $session->game))->bonusConfig()['microgaming'] ?? []);
        $account = (array) ($mg['account'] ?? []);
        $iso = $session->user->wallet?->currency;
        $iso = $iso instanceof \BackedEnum ? (string) $iso->value : (string) ($iso ?: 'EUR');
        $balance = $this->balance($session);
        data_set($account, 'core.currencyIsoCode', $iso);
        data_set($account, 'core.userTypeId', 0);
        data_set($account, 'balance.totalInAccountCurrency', $balance);
        data_set($account, 'balance.balances', [['typeId' => 1, 'amountInAccountCurrency' => $balance], ['typeId' => 9, 'amountInAccountCurrency' => 0]]);

        return $account;
    }

    /** @return array<string, mixed> */
    private function tokens(GameSession $session): array
    {
        // newer Titan shells decode the tokens as JWTs (its claims: expiry, product, …); the session is in our URLs, they're never checked
        $jwt = fn (string $aud) => implode('.', array_map(fn ($part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '='), [
            ['typ' => 'JWT', 'alg' => 'RS256'],
            ['iss' => 'https://account/v1', 'exp' => time() + 1800, 'iat' => (string) time(), 'cid' => '22619', 'sub' => $session->token, 'aud' => $aud],
        ])).'.'.rtrim(strtr(base64_encode(hash('sha256', $session->token.$aud, true)), '+/', '-_'), '=');

        return ['userToken' => $jwt('UserSession'), 'userTokenExpiryInSeconds' => 1800,
            'launchTokens' => array_fill(0, 10, $jwt('JoinUserSession')), 'refreshTokens' => [$jwt('RefreshUserSession')]];
    }

    private function balance(GameSession $session): float
    {
        return round((float) $session->user->wallet?->balance, 2);
    }
}
