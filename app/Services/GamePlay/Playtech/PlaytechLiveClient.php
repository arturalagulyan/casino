<?php

namespace App\Services\GamePlay\Playtech;

use RuntimeException;

/**
 * A bare client for Playtech's casino platform behind Betboy, used at import
 * time to read a game's setup and record whole rounds for its outcome
 * library. Demo sessions only (Betboy `isDemo`, fun credits).
 *
 * Wire: Socket.IO 0.9. A GET of <host>/pt/commonStatic//session.php?game=<rgame>&/1/
 * answers `<sid>:<heartbeat>:<close>:websocket`; the WebSocket is then
 * <host>/pt/ws/1/websocket/<sid>. Frames are text: `1::` (connected),
 * `2::` (heartbeat, echoed), `3:::{json}` (a message). Every message carries
 * its type as `ID` (40021 CasinoSpinRequest → 40022 CasinoSpinResponse, …)
 * and the client's running `umid`. Money is in cents.
 */
class PlaytechLiveClient
{
    /** @var resource|null */
    private $socket = null;

    private int $umid = 0;

    public string $windowId = '';

    /** @var array<string, mixed> setup replies by name */
    public array $init = [];

    public int $credit = 0;

    /** @var list<array<string, mixed>> pushes seen since the last take() */
    public array $pushes = [];

    /**
     * @param  string  $host  the session host (…joker-entry.link)
     * @param  string  $rgame  the client name (whk_ptMobile)
     * @param  string  $game  the game's short name (whk)
     * @param  string  $user  the launch's username
     * @param  string  $token  the launch's temptoken
     */
    public function __construct(public string $host, public string $rgame, public string $game, public string $user, public string $token) {}

    /** From the launch page URL (…/pt/frame.html?game=whk&rgame=whk_ptMobile&username=…&temptoken=…&sessionID=<host>). */
    public static function fromUrl(string $url): self
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $host = (string) ($q['sessionID'] ?? parse_url($url, PHP_URL_HOST));
        if (! isset($q['rgame'], $q['game'])) {
            throw new RuntimeException('Not a Playtech launch: '.mb_strimwidth($url, 0, 200));
        }

        return new self($host, (string) $q['rgame'], (string) $q['game'], (string) ($q['username'] ?? ''), (string) ($q['temptoken'] ?? $q['rgame']));
    }

    /** @return array<string, mixed> the setup replies */
    public function open(): array
    {
        $this->connect();
        $this->umid = 0;
        $this->windowId = self::rid();
        $this->send(['urlTypeList' => ['history', 'link_responsible'], 'casinoName' => '', 'clientSkin' => '', 'clientPlatform' => 'flash', 'clientType' => 'casino', 'language' => 'EN', 'ID' => 31031]);
        $this->init['login'] = $this->call(['sessionToken' => $this->token, 'fingerprint' => '', 'userName' => $this->user, 'mode' => 'REAL_MONEY',
            'version' => '89', 'lang' => 'EN', 'casinoName' => '', 'deviceId' => 'PTP36#0000'.self::rid().'+B+none', 'clienttype' => 'casino',
            'clientPlatform' => 'flash', 'multiDialogSupport' => '1', 'deliveryPlatform' => '', 'deviceFamily' => 'Desktop', 'osName' => 'Unidentified',
            'webchatsupport' => true, 'brokenGameVersion' => 0, 'ID' => 10001], [10002]);
        // a demo session has no currency; a real-money one would carry its code
        if (trim((string) ($this->init['login']['token']['currency'] ?? '')) !== '') {
            $this->close();
            throw new RuntimeException("Playtech opened a session in {$this->init['login']['token']['currency']}, not a demo — refusing to stake real money.");
        }
        $this->init['limits'] = $this->call(['gameShortName' => $this->game, 'ID' => 40024], [40025]);
        $this->init['broken'] = $this->call(['windowId' => $this->windowId, 'ID' => 40036], [40037]);
        $this->take();
        $login = $this->call(['version' => '1.0', 'coinAge' => 1, 'gameShortName' => $this->game, 'gameTechnology' => 'NGM-HTML5',
            'minBet' => 0, 'maxBet' => 0, 'minPosBet' => 0, 'maxPosBet' => 0, 'windowId' => $this->windowId, 'ID' => 40020], [40026, 40027]);
        if ((int) ($login['ID'] ?? 0) !== 40026) {
            throw new RuntimeException('Playtech game login refused: '.json_encode($login));
        }
        // the game's own setup messages (Batman Begins 48940 …) come with it
        $this->init['gameLogin'] = array_merge(array_values(array_filter($this->take(), fn ($m) => ! self::isBalance($m))), [$login]);
        $this->credit = (int) ($login['data']['credit'] ?? 0);

        return $this->init;
    }

    /** A balance notification (10006, 40083, 40085) or the keepalive answer (18). */
    public static function isBalance(array $m): bool
    {
        return in_array((int) ($m['ID'] ?? 0), [10006, 40083, 40085, 18], true);
    }

    /** The paid spin the next round() starts with, already played. */
    private ?array $pending = null;

    /** Free spins / respins still owed in the round being played. */
    private int $left = 0;

    private string $featureType = 'free';

    /** Seconds to wait for an answer to the likeliest next request, and to each other candidate
     *  (a wrong request gets no answer at all; right ones come in well under a second). */
    private const int GUESS_WAIT = 6;

    private const int RETRY_WAIT = 4;

    /**
     * One whole round, as the client plays it: the paid spin, then each free
     * spin / respin it awards. Which spin type comes next is told by the
     * replies — free spins counted from `freespins.numFreeSpins`, respins
     * from `spinData.numRespins` / `nextRespin` / `respinWon` — and when a
     * guess is wrong the server just doesn't answer, so the other types are
     * tried in turn. A round is over when a regular spin is accepted; that
     * spin is the next round's first step, so the round's win is known then:
     * the credit before it, less the credit after this round's stake.
     *
     * @param  array{bet: int, lines: int, before?: list<array<string, mixed>>, custom?: int|null}  $spec
     * @return array{start_credit: int, end_credit: int, steps: list<array{t: string, c: int, m: list<array<string, mixed>>}>}
     */
    public function round(array $spec): array
    {
        $bet = (int) $spec['bet'];
        if ($this->pending === null) {
            $this->pending = $this->step($spec, 'regular', 30);
        }
        $steps = [$this->pending];
        $this->pending = null;
        $this->left = 0;
        $this->observe($steps[0]);
        while (true) {
            $last = $steps[count($steps) - 1];
            $guess = $this->guess($last);
            $order = array_values(array_unique(array_merge([$guess], $guess === 'regular' ? [$this->featureType, 'free', 're'] : ['regular', 'free', 're'])));
            // the game's feature requests (a pick, a "start feature"), each a candidate: [request, …]
            foreach ((array) ($spec['feature'] ?? []) as $q) {
                $order[] = $q;
            }
            // what answered after a step like this one before goes first
            $sig = self::signature($last);
            if (isset($this->learned[$sig])) {
                $order = array_values(array_filter($order, fn ($o) => $o !== $this->learned[$sig]));
                array_unshift($order, $this->learned[$sig]);
            }
            $next = null;
            // a feature the server waits on to be started: the client's CasinoStartBonusGameRequest
            // (40029, no answer), then the same again
            // — then the game's own requests first (the player's choice comes before the free games)
            $mine = array_values(array_filter($order, 'is_array'));
            $order = array_merge($order, [['ID' => 40029, 'silent' => true]], $mine, array_values(array_filter($order, 'is_string')));
            $started = false;
            foreach ($order as $k => $o) {
                if (is_array($o) && ! empty($o['silent'])) {
                    if (! $started) {
                        $this->send(['windowId' => $this->windowId, 'ID' => (int) $o['ID']]);
                        $started = true;
                    }

                    continue;
                }
                if (is_array($o) && ! $started) {   // a feature request follows the start, as the client does it
                    $this->send(['windowId' => $this->windowId, 'ID' => 40029]);
                    $started = true;
                }
                // a request whose field takes one of several values: a value not used yet this round, a few tries
                foreach (is_array($o) ? $this->variants($o, $steps) : [$o] as $try) {
                    if (getenv('PT_TRACE')) {
                        fwrite(STDERR, date('H:i:s').'    try '.json_encode($try)."\n");
                    }
                    try {
                        $next = is_array($try) ? $this->request($try, $k === 0 ? self::GUESS_WAIT : self::RETRY_WAIT) : $this->step($spec, $try, $k === 0 ? self::GUESS_WAIT : self::RETRY_WAIT);
                        $this->learned[$sig] = $o;
                        if (getenv('PT_TRACE')) {
                            fwrite(STDERR, '      ← '.mb_strimwidth(json_encode(array_map(fn ($m) => ['ID' => $m['ID'] ?? null] + array_diff_key((array) ($m['data'] ?? []), ['display' => 1, 'windowId' => 1, 'results' => 1, 'reelset' => 1, 'balance' => 1, 'rows' => 1, 'offsets' => 1]), $next['m'])) ?: '', 0, 400)."\n");
                        }
                        break 2;
                    } catch (RuntimeException $e) {
                        if (! str_contains($e->getMessage(), 'timed out') && ! str_contains($e->getMessage(), 'no reply')) {
                            throw $e;
                        }
                    }
                }
            }
            if ($next === null) {
                throw new RuntimeException('Playtech answered nothing after: '.mb_strimwidth(json_encode($last['m']) ?: '', 0, 600));
            }
            if ($next['t'] === 'regular') {
                $this->pending = $next;
                break;
            }
            if ($next['t'] !== 'q') {
                $this->featureType = $next['t'];
            }
            $steps[] = $next;
            $this->observe($next);
            if (count($steps) > 400) {
                throw new RuntimeException('Playtech round never ended (400 spins)');
            }
        }
        $start = (int) $steps[0]['c'] + $bet;

        return ['start_credit' => $start, 'end_credit' => (int) $this->pending['c'] + $bet, 'steps' => $steps];
    }

    /**
     * The concrete requests to try for a feature request spec: `vary` fields
     * get a random value from their list (one not sent yet in this round
     * first), `$name` values come from the last reply's data.
     *
     * @param  array<string, mixed>  $o
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function variants(array $o, array $steps): array
    {
        $vary = (array) ($o['vary'] ?? []);
        unset($o['vary']);
        $data = [];
        foreach ($steps[count($steps) - 1]['m'] as $m) {
            $data += (array) ($m['data'] ?? []);
        }
        foreach ($o as $k => $v) {
            if (is_string($v) && str_starts_with($v, '$')) {
                $o[$k] = $data[substr($v, 1)] ?? '';
            }
        }
        if ($vary === []) {
            return [$o];
        }
        $field = array_key_first($vary);
        $used = [];
        foreach ($steps as $st) {
            if (isset($st['q'][$field]) && (int) ($st['q']['ID'] ?? 0) === (int) $o['ID']) {
                $used[] = $st['q'][$field];
            }
        }
        $fresh = array_values(array_filter((array) $vary[$field], fn ($v) => ! in_array($v, $used, true)));
        shuffle($fresh);
        // every value already sent this round (all of Robocop's 15 picks): the server answers repeats too,
        // which would hide the next request (the bonus choice) — so don't send this one again
        if (! empty($o['once']) && $fresh === []) {
            return [];
        }
        unset($o['once']);

        return array_map(fn ($v) => $o + [$field => $v], array_slice($fresh ?: (array) $vary[$field], 0, 3));
    }

    /** @var array<string, string|array<string, mixed>> what answered after a step of each signature */
    private array $learned = [];

    /** A step's shape: its type and the (non-empty) fields its messages carry beyond a plain spin's. */
    private static function signature(array $step): string
    {
        $keys = [];
        foreach ($step['m'] as $m) {
            $keys[] = 'ID'.($m['ID'] ?? '');
            // flags beside data (Penguin's nextRespin) tell a respin trigger from a plain spin
            foreach ($m as $k => $v) {
                if (! in_array($k, ['ID', 'umid', 'data'], true) && ! empty($v) && is_scalar($v)) {
                    $keys[] = '^'.$k;
                }
            }
            foreach ((array) ($m['data'] ?? []) as $k => $v) {
                if (! in_array($k, ['reelset', 'spinType', 'display', 'credit', 'balance', 'results', 'windowId'], true) && ! empty($v)) {
                    $keys[] = $k;
                }
            }
        }
        sort($keys);

        return $step['t'].':'.implode(',', array_unique($keys));
    }

    /**
     * A feature request (a pick, "start the feature") — answered by the
     * first message that isn't a balance update.
     *
     * @param  array<string, mixed>  $q
     * @return array{t: string, q: array<string, mixed>, c: int, m: list<array<string, mixed>>}
     */
    private function request(array $q, int $timeout): array
    {
        // 'reply': the answer that means the server took it (Robocop answers anything with an empty
        // 48553 while it waits for the bonus type to be chosen)
        $expect = isset($q['reply']) ? (int) $q['reply'] : null;
        unset($q['reply']);
        $this->take();
        $this->send($q + ['windowId' => $this->windowId]);
        $reply = $this->await(fn (array $m) => ! self::isBalance($m), $timeout);
        if ($expect !== null && (int) ($reply['ID'] ?? 0) !== $expect) {
            $this->drain(0.35);

            throw new RuntimeException('Playtech sent no reply (answered '.($reply['ID'] ?? '?').' instead of '.$expect.')');
        }
        $after = array_values(array_filter($this->drain(0.35), fn ($m) => ! self::isBalance($m)));

        return ['t' => 'q', 'q' => $q, 'c' => (int) ($reply['data']['credit'] ?? $this->credit), 'm' => array_merge([$reply], $after)];
    }

    /** @return array{t: string, c: int, m: list<array<string, mixed>>} */
    private function step(array $spec, string $type, int $timeout): array
    {
        $this->take();
        $reply = $this->spin((int) $spec['bet'], (int) $spec['lines'], $type, (array) ($spec['before'] ?? []), $timeout, $spec['custom'] ?? null);
        if ((int) ($reply['ID'] ?? 0) === 40023) {
            throw new RuntimeException('Playtech refused the spin: '.json_encode($reply));
        }
        $before = array_values(array_filter($this->take(), fn ($m) => ! self::isBalance($m)));
        $after = array_values(array_filter($this->drain(0.35), fn ($m) => ! self::isBalance($m)));
        // the pre-spin message's own answer (Gladiator's 49080) is not part of the round
        $before = array_values(array_filter($before, fn ($m) => ! isset($m['data']) || array_keys((array) $m['data']) !== ['windowId']));

        return ['t' => $type, 'c' => (int) ($reply['data']['credit'] ?? 0), 'm' => array_merge($before, [$reply], $after)];
    }

    /** Count what a step awards. */
    private function observe(array $step): void
    {
        foreach ($step['m'] as $m) {
            $d = (array) ($m['data'] ?? []);
            if (isset($d['freespins']['numFreeSpins'])) {
                $this->left += (int) $d['freespins']['numFreeSpins'];
            }
            if (isset($d['spinData']['numRespins']) && $step['t'] === 'regular') {
                $this->left += (int) $d['spinData']['numRespins'];
            }
        }
        if ($step['t'] === 'free' || $step['t'] === 're') {
            $this->left = max(0, $this->left - 1);
        }
    }

    private function guess(array $step): string
    {
        foreach ($step['m'] as $m) {
            $d = (array) ($m['data'] ?? []);
            if (! empty($m['nextRespin']) || ! empty($d['nextRespin']) || (! empty($d['respinWon']) && $step['t'] === 'regular')) {
                return 're';
            }
            if (($d['bonusState'] ?? null) === 'continue') {
                return in_array($step['t'], ['free', 're'], true) ? $step['t'] : 'free';
            }
            if (isset($d['spinData']['numRespins'])) {
                return 're';
            }
        }

        return $this->left > 0 ? (in_array($step['t'], ['free', 're'], true) ? $step['t'] : $this->featureType) : 'regular';
    }

    /**
     * Send one message and wait for the first message of one of $ids;
     * everything else that arrives meanwhile goes to $pushes.
     *
     * @param  array<string, mixed>  $msg
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function call(array $msg, array $ids, int $timeout = 30): array
    {
        $this->send($msg);

        return $this->await($ids, $timeout);
    }

    /**
     * One spin: the client's CasinoSpinRequest, answered by the game's own
     * spin response — 40022 for the plain slots, a per-game type for the
     * others (Batman Begins 48941, Gladiator 49086 …) — so: the first
     * message with a `display`, or a 40023 error.
     *
     * @param  list<array<string, mixed>>  $before  game-specific messages the client sends first (Gladiator's 49082)
     * @return array<string, mixed>
     */
    public function spin(int $bet, int $lines, string $type = 'regular', array $before = [], int $timeout = 30, ?int $customId = null): array
    {
        foreach ($before as $msg) {
            $this->send($msg + ['windowId' => $this->windowId]);
        }
        // most games send the platform's CasinoSpinRequest; a few their own (Penguin Prize: 48385 {type, bet})
        $this->send($customId
            ? ['type' => $type, 'bet' => $bet, 'windowId' => $this->windowId, 'ID' => $customId]
            : ['bet' => $bet, 'numCoins' => 1, 'lines' => array_fill(0, $lines, 1), 'spinType' => $type, 'windowId' => $this->windowId, 'ID' => 40021]);

        return $this->await(fn (array $m) => isset($m['data']['display']) || (int) ($m['ID'] ?? 0) === 40023, $timeout);
    }

    /** @param list<int>|callable(array<string, mixed>): bool $ids @return array<string, mixed> */
    public function await(array|callable $ids, int $timeout = 30): array
    {
        $match = is_callable($ids) ? $ids : fn (array $m) => in_array((int) ($m['ID'] ?? 0), $ids, true);
        $until = microtime(true) + $timeout;
        // read in short slices so $timeout holds (a blocking read would wait the socket's 30 s)
        stream_set_timeout($this->socket, 0, 250000);
        try {
            while (microtime(true) < $until) {
                $m = $this->receive(true);
                if ($m === null) {
                    continue;
                }
                if ($match($m)) {
                    if (isset($m['data']['credit']) && is_numeric($m['data']['credit'])) {
                        $this->credit = (int) $m['data']['credit'];
                    }

                    return $m;
                }
                $this->pushes[] = $m;
            }
        } finally {
            stream_set_timeout($this->socket, 30);
        }
        throw new RuntimeException('Playtech sent no '.(is_array($ids) ? implode('/', $ids) : 'reply'));
    }

    /** Collect pushes for a moment (balance updates follow a spin reply). @return list<array<string, mixed>> */
    public function drain(float $seconds = 0.6, float $quiet = 0.15): array
    {
        // pushes come in a burst right after the reply: stop once the line has been quiet for $quiet
        $start = microtime(true);
        $until = $start + $seconds;
        $last = $start;
        stream_set_timeout($this->socket, 0, 50000);
        try {
            while (($now = microtime(true)) < $until && $now - $last < $quiet) {
                $m = $this->receive(true);
                if ($m !== null) {
                    $this->pushes[] = $m;
                    $last = microtime(true);
                }
            }
        } finally {
            stream_set_timeout($this->socket, 30);
        }

        return $this->take();
    }

    /** @return list<array<string, mixed>> */
    public function take(): array
    {
        [$out, $this->pushes] = [$this->pushes, []];

        return $out;
    }

    /** @param array<string, mixed> $msg */
    public function send(array $msg): void
    {
        $msg['umid'] = ++$this->umid;
        $this->frame('3:::'.json_encode($msg, JSON_UNESCAPED_SLASHES));
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** One message, or null for a control frame (heartbeats are echoed). */
    private function receive(bool $soft = false): ?array
    {
        $raw = $this->readFrame($soft);
        if ($raw === null) {
            return null;
        }
        if (str_starts_with($raw, '2::')) {
            $this->frame('2::');

            return null;
        }
        if (str_starts_with($raw, '0::')) {
            throw new RuntimeException('Playtech closed the session');
        }
        if (! str_starts_with($raw, '3:::')) {
            return null;
        }
        $m = json_decode(substr($raw, 4), true);

        return is_array($m) ? $m : null;
    }

    private static function rid(): string
    {
        $abc = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        return implode('', array_map(fn () => $abc[random_int(0, 61)], range(1, 6)));
    }

    private function connect(): void
    {
        $this->close();
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $hs = @file_get_contents("https://{$this->host}/pt/commonStatic//session.php?game={$this->rgame}&/1/?t=".intdiv(hrtime(true), 1000000), false, $ctx);
        if (! is_string($hs) || substr_count($hs, ':') < 3) {
            throw new RuntimeException('Playtech handshake failed: '.mb_strimwidth((string) $hs, 0, 200));
        }
        $sid = strtok($hs, ':');
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $this->host]]);
        $socket = @stream_socket_client("tls://{$this->host}:443", $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$this->host}: {$error}");
        }
        fwrite($socket, "GET /pt/ws/1/websocket/{$sid} HTTP/1.1\r\nHost: {$this->host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            .'Sec-WebSocket-Key: '.base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\nOrigin: https://static.joker-entry.link\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
        $head = '';
        while (! str_contains($head, "\r\n\r\n")) {
            $c = fread($socket, 1);
            if ($c === '' || $c === false) {
                throw new RuntimeException('WebSocket handshake failed');
            }
            $head .= $c;
        }
        if (! str_contains($head, ' 101 ')) {
            throw new RuntimeException('WebSocket handshake refused: '.strtok($head, "\r\n"));
        }
        stream_set_timeout($socket, 30);
        $this->socket = $socket;
    }

    private function frame(string $message): void
    {
        $len = strlen($message);
        $mask = random_bytes(4);
        $head = chr(0x81).match (true) {
            $len < 126 => chr(0x80 | $len),
            $len < 65536 => chr(0x80 | 126).pack('n', $len),
            default => chr(0x80 | 127).pack('J', $len),
        };
        fwrite($this->socket, $head.$mask.($message ^ str_repeat($mask, intdiv($len, 4) + 1)));
    }

    private function readFrame(bool $soft = false): ?string
    {
        $message = '';
        do {
            $h = $this->readBytes(2, $soft && $message === '');
            if ($h === null) {
                return null;
            }
            $fin = ord($h[0]) & 0x80;
            $opcode = ord($h[0]) & 0x0F;
            $len = ord($h[1]) & 0x7F;
            if ($len === 126) {
                $len = unpack('n', $this->readBytes(2))[1];
            } elseif ($len === 127) {
                $len = unpack('J', $this->readBytes(8))[1];
            }
            $data = $len ? $this->readBytes($len) : '';
            if ($opcode === 8) {
                throw new RuntimeException('Playtech closed the session');
            }
            if ($opcode === 9 || $opcode === 10) {
                $fin = 0;

                continue;
            }
            $message .= $data;
        } while (! $fin);

        return $message;
    }

    /** $soft: a timeout before the first byte returns null instead of throwing */
    private function readBytes(int $n, bool $soft = false): ?string
    {
        $buf = '';
        $start = microtime(true);
        while (strlen($buf) < $n) {
            $chunk = fread($this->socket, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                $timedOut = stream_get_meta_data($this->socket)['timed_out'];
                if ($soft && $timedOut && $buf === '') {
                    return null;
                }
                // mid-frame (or a short read slice): keep reading, up to 30 s in all
                if ($timedOut && ($buf !== '' || ! $soft) && microtime(true) - $start < 30) {
                    continue;
                }
                throw new RuntimeException($timedOut ? 'Playtech timed out' : 'Playtech closed the session');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
