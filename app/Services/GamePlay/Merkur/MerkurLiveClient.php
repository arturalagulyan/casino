<?php

namespace App\Services\GamePlay\Merkur;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for Merkur's (Edict "EOC") demo game server, used at import
 * time to read a game's setup and record whole rounds for its outcome
 * library. Fun credits only: Betboy's demo launch, a casino with no currency.
 *
 * Wire: JSON over HTTP to the session host's `skvCore/WebEngine.php`, the
 * REST path in its query string —
 *
 *   GET  ?req=whow-fm&type=gateway&/players/null/<game>/templates/default/money/{5}   launcher data (templateId, client config)
 *   POST ?/services/gaming/5/v2/gateway/gamesession/init?casinoName=whow-fm&gameMode=money
 *   POST ?/services/gaming/5/v2/gateway/casinos/whow-fm/v2/templates/<tpl>/money/commands/start
 *   POST ?…/commands/commands   {"de.edict.eoc.gaming.comm.GameSessionRequestDTO":{"command":"command","gameData":"<json>","gameSessionUuid":"uuid"}}
 *
 * `gameData` is a JSON string: {gameActionId: INIT | PLAY | FINISH_GAME |
 * RISK_* …, wagers: [{wager, wagerPositionId}], gameParams}. The reply's
 * `coreData` holds the depot (cents) and `isGameFinished`; its `gameData`
 * (a JSON string again) the result, `nextGameActions` and the flow.
 */
class MerkurLiveClient
{
    public const string CASINO = 'whow-fm';

    private const string GATEWAY = '/services/gaming/5/v2/gateway';

    /** Baker Street's duel: gameParams.userChoice — 1 the next offer (PLAY), 0 take it (FINISH_GAME). */
    public const string DUEL_CHOICE = 'userChoice';

    public const int DUEL_NEXT = 1;

    public const int DUEL_TAKE = 0;

    private CookieJar $jar;

    private string $templateId = '';

    /** @var array<string, mixed> launcher data (gateway reply) */
    public array $launcher = [];

    /** @var array<string, mixed> the casino's domain settings (`req=whow-fm`) */
    public array $domain = [];

    /** @var array<string, mixed> the INIT reply's game data */
    public array $init = [];

    private int $depot = 0;

    public function __construct(private readonly string $engine, private readonly string $game)
    {
        $this->jar = new CookieJar;
    }

    /** Betboy's Merkur page: `…/mk/iframe2.html?game=<id>&serverURL=https://<session>/skvCore/WebEngine.php?&session=…`. */
    public static function fromPage(string $page): self
    {
        parse_str((string) parse_url($page, PHP_URL_QUERY), $q);
        $server = rtrim((string) ($q['serverURL'] ?? ''), '?&');
        $game = (string) ($q['game'] ?? '');
        if ($server === '' || $game === '') {
            throw new RuntimeException("Not a Merkur launch page (no serverURL / game): {$page}");
        }

        return new self($server, $game);
    }

    /**
     * Open the session and play INIT.
     *
     * @return array{launcher: array<string, mixed>, domain: array<string, mixed>, init: array<string, mixed>, depot: int}
     */
    public function open(): array
    {
        $this->domain = (array) ($this->get('req='.self::CASINO)['domainDTO'] ?? []);
        $gw = $this->get('req='.self::CASINO."&type=gateway&/players/null/{$this->game}/templates/default/money/{5}?restart=false");
        $this->launcher = (array) ($gw['gameLauncherData'] ?? []);
        $this->templateId = (string) ($this->launcher['templateId'] ?? '');
        if ($this->templateId === '') {
            throw new RuntimeException('Merkur gateway named no template: '.mb_strimwidth(json_encode($gw) ?: '', 0, 300));
        }
        if (($this->launcher['clientCurrency'] ?? '') !== '' || ! empty($this->launcher['gameNotPlayableMessage']['uiElements'] ?? [])) {
            throw new RuntimeException('Merkur opened a session in '.($this->launcher['clientCurrency'] ?? '?').', not a fun-credit demo — refusing to stake real money.');
        }
        $this->post(self::GATEWAY.'/gamesession/init?casinoName='.self::CASINO.'&gameMode=money', ['de.edict.eoc.gaming.comm.RequestDTO' => ['command' => 'init']]);
        $start = $this->post($this->commands('start').'&portalLocale=en', ['de.edict.eoc.gaming.comm.JoinSessionRequestDTO' => [
            'command' => 'start', 'coreVersion' => '${project.version}',
            'gameInfo' => ['gameVersion' => 0, 'gameManufacturer' => 'unused', 'gameType' => 'unused'], 'gameTemplateName' => '',
        ]]);
        $this->depot = (int) ($start['de.edict.eoc.gaming.comm.JoinSessionResponseDTO']['depot']['balance'] ?? 0);
        $reply = $this->command(['gameActionId' => 'INIT']);
        $this->init = $reply['game'];

        return ['launcher' => $this->launcher, 'domain' => $this->domain, 'init' => $this->init, 'depot' => $this->depot];
    }

    /** Fun credits left, in cents. */
    public function depot(): int
    {
        return $this->depot;
    }

    /**
     * Play one whole round: PLAY at `$wager` on every wager position (line)
     * of the largest set, then on until the game is finished — free games go
     * on with PLAY, a win waiting for the gamble choice is collected
     * (FINISH_GAME, never gambled). `$coin` is what the client would show
     * (the wager, or the total for a split-coin game).
     *
     * @param  list<int>  $positions
     * @return array{start_depot: int, steps: list<array{action: string, core: array<string, mixed>, game: array<string, mixed>}>}
     */
    public function round(int $wager, array $positions, ?int $coin = null): array
    {
        $start = $this->depot;
        $wagers = array_map(fn (int $p) => ['wager' => $wager, 'wagerPositionId' => $p], $positions);
        $play = ['wagers' => $wagers, 'gameParams' => ['REQUIRED_SYMBOL_COUNT' => -1, 'REQUIRED_MINIMAL_DURATION' => 1,
            'CLIENT_SETTINGS' => json_encode(['coin' => $coin ?? $wager, 'countOfActiveWagerPositions' => count($positions), 'custom' => null]),
            'IS_AUTOSPIN' => false, 'IS_QUICKSPIN' => false], 'gameActionId' => 'PLAY'];
        $steps = [];
        $request = $play;
        $duel = false;
        for ($n = 0; ; $n++) {
            if ($n > 400) {
                throw new RuntimeException('A Merkur round ran past 400 steps');
            }
            $reply = $this->command($request);
            $steps[] = ['action' => (string) $request['gameActionId'], 'core' => $reply['core'], 'game' => $reply['game']];
            $flow = (string) ($reply['game']['nextGameFlowName'] ?? '');
            $next = self::actions($reply['game']);
            // done: finished, back to the main game (Baker Street's duel keeps isGameFinished set throughout)
            if (! empty($reply['core']['isGameFinished']) && ! in_array($flow, ['ADD_ON_GAME', 'RISK_CHOICE', 'FREE_GAME'], true)) {
                break;
            }
            if ($flow === 'ADD_ON_GAME') {   // Baker Street's duel: always the next offer, to the end
                $name = (string) ($reply['game']['addOnGameInitResult']['creatorName'] ?? $reply['game']['addOnGameResult']['creatorName'] ?? '');
                if ($name !== 'DUEL' || ! in_array('PLAY', $next, true)) {
                    throw new RuntimeException("The round went on to an add-on game ({$name}) that is not recorded");
                }
                $duel = true;
                $request = ['gameActionId' => 'PLAY', 'gameParams' => [self::DUEL_CHOICE => self::DUEL_NEXT], 'wagers' => null];
            } elseif (in_array('PLAY', $next, true)) {          // free games (and other in-round plays): no wagers, as the client sends
                $request = ['wagers' => []] + $play;
            } elseif (in_array('FINISH_GAME', $next, true)) {   // collect (never gambled); after the duel the server wants gameParams {}
                $request = ['gameActionId' => 'FINISH_GAME', 'gameParams' => $duel ? (object) [] : null, 'wagers' => null];
            } else {
                throw new RuntimeException('A Merkur round offers neither PLAY nor FINISH_GAME: '.implode(',', $next).' (flow '.$flow.')');
            }
        }

        return ['start_depot' => $start, 'steps' => $steps];
    }

    /**
     * What every recorded round plays, from an INIT: all lines of the largest
     * set at the smallest total stake (PLAY's minTotalWager — the smallest
     * coin, × the lines unless the coin is the total already).
     *
     * @return array{positions: list<int>, wager: int, coin: int, stake: int}
     */
    public static function recording(array $init, bool $splitCoins): array
    {
        $sets = (array) ($init['wagerPositionSets'] ?? []);
        usort($sets, fn ($a, $b) => count((array) $a['wagerPositionIds']) <=> count((array) $b['wagerPositionIds']));
        $positions = array_map('intval', (array) (end($sets)['wagerPositionIds'] ?? []));
        $n = max(1, count($positions));
        $coin = (int) min((array) ($init['coins'] ?? [1]));
        $wager = $splitCoins ? max(1, intdiv($coin, $n)) : $coin;

        return ['positions' => $positions, 'wager' => $wager, 'coin' => $coin, 'stake' => $wager * $n];
    }

    /** Split coins (coin = total bet): as the client's script says, else when the game has one fixed line set. */
    public static function splitCoins(array $init, ?bool $fromScript): bool
    {
        return $fromScript ?? count((array) ($init['wagerPositionSets'] ?? [])) === 1;
    }

    /** @return list<string> the ids of a reply's `nextGameActions` */
    public static function actions(array $game): array
    {
        return array_values(array_map(fn ($a) => (string) ($a['id'] ?? ''), (array) ($game['nextGameActions'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $gameData
     * @return array{core: array<string, mixed>, game: array<string, mixed>}
     */
    public function command(array $gameData): array
    {
        $r = $this->post($this->commands('commands'), ['de.edict.eoc.gaming.comm.GameSessionRequestDTO' => [
            'command' => 'command', 'gameData' => ($gameData['gameActionId'] ?? '') === 'INIT' ? $gameData : json_encode($gameData), 'gameSessionUuid' => 'uuid',
        ]]);
        $dto = $r['de.edict.eoc.gaming.comm.GameClientActionResponseDTO'] ?? null;
        if (! is_array($dto)) {
            throw new RuntimeException('Merkur '.($gameData['gameActionId'] ?? '?').': '.mb_strimwidth(json_encode($r) ?: '', 0, 300));
        }
        $game = json_decode((string) ($dto['gameData'] ?? ''), true);
        if (! is_array($game)) {
            throw new RuntimeException('Merkur '.($gameData['gameActionId'] ?? '?').': no game data');
        }
        $core = (array) ($dto['coreData'] ?? []);
        $this->depot = (int) ($core['depot']['balance'] ?? $this->depot);

        return ['core' => $core, 'game' => $game];
    }

    public function close(): void
    {
        // the demo session expires on its own
    }

    private function commands(string $cmd): string
    {
        return self::GATEWAY.'/casinos/'.self::CASINO."/v2/templates/{$this->templateId}/money/commands/{$cmd}";
    }

    /** @return array<string, mixed> */
    private function get(string $query): array
    {
        $r = Http::withUserAgent('Mozilla/5.0')->withOptions(['cookies' => $this->jar])->timeout(30)->get("{$this->engine}?{$query}");

        return $this->decode($r->status(), $r->body(), $query);
    }

    /** @return array<string, mixed> */
    private function post(string $query, array $body): array
    {
        $r = Http::withUserAgent('Mozilla/5.0')->withOptions(['cookies' => $this->jar])->timeout(30)
            ->withBody(json_encode($body) ?: '{}', 'application/json')->post("{$this->engine}?{$query}");

        return $this->decode($r->status(), $r->body(), $query);
    }

    /** @return array<string, mixed> */
    private function decode(int $status, string $body, string $query): array
    {
        $json = json_decode($body, true);
        if ($status !== 200 || ! is_array($json)) {
            throw new RuntimeException("Merkur {$query}: HTTP {$status} ".mb_strimwidth($body, 0, 200));
        }

        return $json;
    }
}
