<?php

namespace App\Services\GamePlay\PlayNGo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for Play'n GO's practice (demo) game server, used at import
 * time to read a game's init and sample real spins so the maths we build can
 * be checked against what Play'n GO actually pays. Practice money only.
 *
 * Wire: the session is opened with two HTTP line-protocol posts (103 open,
 * 101 login), then every packet goes over a SignalR hub (`/gamehub`, the
 * MessagePack protocol) as `ProcessPacket("d=<seq>\r\n<token>\r\n<lines>")`;
 * the server answers `Commands("d=<lines>")`.
 */
class PlayNGoLiveClient
{
    /** Least time between two packets, ms ({@see packet()}). */
    public int $minGapMs = 1100;

    private float $lastPacketAt = 0.0;

    public const string DEFAULT_SERVER = 'https://ascflyp.playngonetwork.com/';

    /** @var resource|null */
    private $socket = null;

    private int $seq = 0;

    private string $token = '';

    public function __construct(private readonly string $server = self::DEFAULT_SERVER) {}

    /**
     * Open a practice session for numeric game `$gameId` and send the game
     * init (104) with `$settings` (the client's denomination XML); returns its reply.
     */
    public function open(int $gameId, string $name, string $settings, string $channel = 'desktop'): string
    {
        $this->seq = 0;
        $ua = rawurlencode('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36');
        $session = $this->post('0', "103 12 \"en_GB\" {$gameId} \"{$ua}\" \"{$name}\" \"{$channel}\"");
        if (! preg_match('/^d=103 "([^"]+)"/', $session, $m)) {
            throw new RuntimeException("Play'n GO refused a session: ".trim($session));
        }
        $login = $this->post($m[1], '101 "" "" "" "" "" ""');
        if (! preg_match('/^d=103 "([^"]+)"/', $login, $l)) {
            throw new RuntimeException("Play'n GO refused the login: ".trim($login));
        }
        $this->token = $l[1];
        $this->connect();

        // as the real client sends it: the quoted XML form-encoded whole (`%22%3croot%3e…+Value…%22`)
        return $this->packet("104 {$gameId} 0 ".strtolower(urlencode('"'.$settings.'"')));
    }

    /** Send one packet's lines and return the reply's lines (without the "d=" prefix). */
    public function packet(string $lines): string
    {
        // Play'n GO cuts a session off past about one packet a second — and a
        // feature round sends a burst (`2 6`, a `7 n` per free spin): spaced
        // here, or every bonus round would be the one cut off, and lost
        $wait = (int) (($this->lastPacketAt + $this->minGapMs / 1000 - microtime(true)) * 1_000_000);
        if ($wait > 0) {
            usleep($wait);
        }
        $this->lastPacketAt = microtime(true);
        $this->seq++;
        $this->write($this->invocation("d={$this->seq}\r\n{$this->token}\r\n{$lines}\r\n"));
        for ($k = 0; $k < 20; $k++) {
            foreach ($this->messages() as $m) {
                if (($m[0] ?? null) === 1 && ($m[3] ?? '') === 'Commands') {
                    return (string) preg_replace('/^d=/', '', (string) ($m[4][0] ?? ''));
                }
                if (($m[0] ?? null) === 7) {
                    throw new RuntimeException("Play'n GO closed the hub: ".json_encode($m));
                }
            }
        }

        throw new RuntimeException("Play'n GO sent no reply");
    }

    /**
     * One whole round at `$spinLine` (e.g. "1 5 10 10 1"), played the way the
     * client plays it — the server shapes its replies by what it is sent, in
     * order. After the spin and each `2 6`:
     *
     *   a balance line (52 …)         the round is settled
     *   in free spins (a running      report each total shown, `7 n` (83 n
     *   total `3 x … L 1`, L ≥ 1)     back) — before anything else
     *   last line `2 5 1`             acknowledge each award, `2 4 k`, then on: `2 6`
     *   otherwise, won or free spins  collect: `4 0`
     *
     * (Book of Dead: one `2 6` brings all ten free spins → `7 0` … `7 10`; Rise
     * of Olympus: a cleared grid's free spin arrives inside a `2 6` → `7 0`
     * before the next `2 6`; Moon Princess's cascades: no reports.) A reply
     * none of these fit is an unknown game state — thrown, named.
     *
     * @return list<array{sent: string, reply: string}>
     */
    public function round(string $spinLine): array
    {
        $log = [['sent' => $spinLine, 'reply' => $reply = $this->packet($spinLine)]];
        // a spin answered without a board (e.g. `90 … "IDS_IERR_INTERNAL"`) is an error, not a losing round
        if (preg_match('/^111\b/m', $reply) || ! preg_match('/^1 \d+ \d+ \d+ /m', $reply)) {
            throw new RuntimeException("Play'n GO error: ".trim($reply));
        }
        $reports = 0;        // `7 n` sent so far
        $freeMode = false;   // the round has entered free spins (a total with spins left)
        $collect = false;    // a win to collect
        for ($k = 0; $k < 400; $k++) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $reply) ?: []), fn ($l) => $l !== ''));
            if (preg_grep('/^111\b/', $lines)) {
                throw new RuntimeException("Play'n GO error: ".trim($reply));
            }
            if (preg_grep('/^52 /', $lines)) {
                return $log;
            }
            // the client's next moves, in its order: report each free-spin total
            // shown (7 n), acknowledge each award (2 4 k), then on (2 6) or collect (4 0)
            $queue = [];
            $totals = preg_grep('/^3 [01] \d+ \d+ \d+ \d+/', $lines);
            foreach ($totals as $t) {
                $f = explode(' ', $t);
                $freeMode = $freeMode || (int) $f[4] >= 1;
                $collect = $collect || (int) $f[2] > 0;
            }
            if ($freeMode) {
                foreach ($totals as $_) {
                    $queue[] = '7 '.$reports++;
                }
            }
            if (end($lines) === '2 5 1') {
                for ($a = 0, $n = count(preg_grep('/^2 2 8 /', $lines)); $a < $n; $a++) {
                    $queue[] = "2 4 {$a}";
                }
                $queue[] = '2 6';
            } elseif ($collect || $freeMode) {
                $queue[] = '4 0';
            } else {
                throw new RuntimeException("Play'n GO: unknown state after '".end($log)['sent']."': ".mb_strimwidth(str_replace(["\r\n", "\n"], ' | ', trim($reply)), 0, 300));
            }
            foreach ($queue as $i => $next) {
                $log[] = ['sent' => $next, 'reply' => $answer = $this->packet($next)];
                if (preg_match('/^111\b/m', $answer)) {
                    throw new RuntimeException("Play'n GO error after '{$next}': ".trim($answer));
                }
                $reply = $answer;   // the last one (2 6 / 4 0) decides what comes next
            }
        }
        $tail = array_map(fn ($x) => $x['sent'].' => '.mb_strimwidth(str_replace(["\r\n", "\n"], ' | ', trim($x['reply'])), 0, 160), array_slice($log, 0, 3) + array_slice($log, -3, 3, true));
        throw new RuntimeException("Play'n GO round never ended (400 steps): ".implode(' ;; ', $tail));
    }

    /**
     * The game-specific lines of Play'n GO's init reply (`104 …`): everything
     * but the session plumbing our server writes itself — e.g. Rise of
     * Olympus's `56 0` (the god in charge), without which its client fails on
     * the first god award and freezes.
     *
     * @return list<string>
     */
    public static function initExtras(string $reply): array
    {
        $ours = ['104', '54', '57', '60', '52', '83', '91', '109'];
        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', (string) preg_replace('/^d=/', '', $reply)) ?: []), fn ($l) => $l !== '');

        return array_values(array_filter($lines, fn ($l) => ! in_array(explode(' ', $l)[0], $ours, true)));
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function post(string $token, string $line): string
    {
        $this->seq++;
        $res = Http::withHeaders(['Content-Type' => 'text/plain;charset=UTF-8', 'Origin' => 'https://released.playngonetwork.com'])
            ->withBody("d={$this->seq}\r\n{$token}\r\n{$line}\r\n", 'text/plain;charset=UTF-8')
            ->timeout(30)->post($this->server);

        return $res->body();
    }

    private function connect(): void
    {
        $this->close();
        $p = parse_url(rtrim($this->server, '/').'//gamehub');
        $host = (string) ($p['host'] ?? '');
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client("tls://{$host}:443", $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$host}: {$error}");
        }
        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET {$p['path']} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: https://released.playngonetwork.com\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
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
        // SignalR: choose the MessagePack protocol (a JSON record ended by 0x1e), answered by "{}"
        $this->frame("{\"protocol\":\"messagepack\",\"version\":1}\x1e", 0x1);
        $this->readFrame();
    }

    /** A SignalR invocation of ProcessPacket, length-prefixed. */
    private function invocation(string $arg): string
    {
        $body = "\x95\x01\x80\xc0".MessagePack::str('ProcessPacket')."\x91".MessagePack::str($arg);

        return MessagePack::varint(strlen($body)).$body;
    }

    /** @return list<mixed> the SignalR messages in the next binary frame */
    private function messages(): array
    {
        return MessagePack::frames($this->readFrame());
    }

    private function write(string $binary): void
    {
        $this->frame($binary, 0x2);
    }

    private function frame(string $message, int $opcode): void
    {
        $len = strlen($message);
        $mask = random_bytes(4);
        $head = chr(0x80 | $opcode).match (true) {
            $len < 126 => chr(0x80 | $len),
            $len < 65536 => chr(0x80 | 126).pack('n', $len),
            default => chr(0x80 | 127).pack('J', $len),
        };
        $body = '';
        for ($i = 0; $i < $len; $i++) {
            $body .= $message[$i] ^ $mask[$i % 4];
        }
        fwrite($this->socket, $head.$mask.$body);
    }

    private function readFrame(): string
    {
        $message = '';
        do {
            $h = $this->readBytes(2);
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
                throw new RuntimeException("Play'n GO closed the session");
            }
            if ($opcode === 9 || $opcode === 10) {
                continue;
            }
            $message .= $data;
        } while (! $fin);

        return $message;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->socket, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                throw new RuntimeException(stream_get_meta_data($this->socket)['timed_out'] ? "Play'n GO timed out" : "Play'n GO closed the session");
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
