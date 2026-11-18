<?php

namespace App\Services\GamePlay\Apollo;

use RuntimeException;

/**
 * A bare client for Apollo Games' demo server, used at import time to read a
 * game's init and record whole rounds for its outcome library. Fun credits
 * only (`demo: true`, currency NAN).
 *
 * Wire: one WebSocket (the launch page's `socketURL`, http→ws), text frames
 * of header lines, a blank line and a JSON body —
 *
 *   name=casino.spin\nContent-Type=application/json\nAuthorization=<token>\n\n{"stake":0.5,…}
 *
 * answered `response=casino.spin\nstatus=200\n…\n\n{…}`. The server also
 * pushes `casino.ping` frames of its own, skipped here.
 */
class ApolloLiveClient
{
    /** @var resource|null */
    private $socket = null;

    private int $sequence = 0;

    private string $token = '';

    /** @var array<string, mixed> */
    public array $init = [];

    public function __construct(private readonly string $socketUrl) {}

    /** The launch page's `socketURL` parameter. */
    public static function fromPage(string $page): self
    {
        parse_str((string) parse_url($page, PHP_URL_QUERY), $q);
        $url = (string) ($q['socketURL'] ?? '');
        if ($url === '') {
            throw new RuntimeException("Not an Apollo launch page (no socketURL): {$page}");
        }

        return new self($url);
    }

    /** @return array<string, mixed> the init reply */
    public function open(): array
    {
        $this->connect();
        $this->init = $this->send('casino.init', [
            'language' => null, 'gameName' => null, 'gameMode' => null,
            'playerHandle' => '[newGameName:Blood,currency:EUR,gameMode:null,playerId:,language:en]',
            'isMobile' => false,
        ]);
        $this->token = (string) ($this->init['token'] ?? 'sessionToken');
        if (($this->init['demo'] ?? false) !== true || ($this->init['balanceCurrency'] ?? '') !== 'NAN') {
            $this->close();
            throw new RuntimeException('Apollo opened a session in '.($this->init['balanceCurrency'] ?? '?').', not a demo — refusing to stake real money.');
        }

        return $this->init;
    }

    public function credit(): float
    {
        return (float) ($this->init['credit'] ?? 0);
    }

    /**
     * Send one action and return its reply's body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function send(string $action, array $body): array
    {
        if ($action !== 'casino.init') {
            $body += ['sessionId' => 'sessionid', 'traceId' => 'NaN', 'sequence' => ++$this->sequence];
        }
        $head = "name={$action}\nContent-Type=application/json\n".($this->token !== '' ? "Authorization={$this->token}\n" : '');
        $this->frame($head."\n".json_encode($body, JSON_UNESCAPED_SLASHES));
        for ($k = 0; $k < 50; $k++) {
            [$h, $reply] = self::parse($this->readFrame());
            if (($h['response'] ?? '') !== $action) {
                continue;   // its own pings
            }
            if (($h['status'] ?? '') !== '200') {
                throw new RuntimeException("Apollo {$action}: status {$h['status']}: ".mb_strimwidth(json_encode($reply) ?: '', 0, 300));
            }
            if (isset($reply['credit'])) {
                $this->init['credit'] = $reply['credit'];
            }

            return (array) $reply;
        }
        throw new RuntimeException("Apollo sent no reply to {$action}");
    }

    /**
     * One whole round at `$stake`: the paid spin, then — as the client does —
     * one more `casino.spin` (no stake taken) per free spin it has left.
     *
     * @return array{start_credit: int, steps: list<array<string, mixed>>} credit in cents
     */
    public function round(float $stake): array
    {
        $start = (int) round($this->credit() * 100);
        $steps = [];
        do {
            $reply = $this->send('casino.spin', ['stake' => $stake]);
            if (! isset($reply['gameState']) || ! is_array($reply['gameState'])) {
                throw new RuntimeException('Apollo spin without a game state: '.mb_strimwidth(json_encode($reply) ?: '', 0, 300));
            }
            $steps[] = $reply;
            if (count($steps) > 500) {
                throw new RuntimeException('Apollo round never ended (500 spins)');
            }
        } while (self::continues($reply));

        return ['start_credit' => $start, 'steps' => $steps];
    }

    /**
     * Whether the client spins on, unpaid, after this reply: free spins left,
     * or (Magic Lady) a second bonus line won but not played yet.
     */
    public static function continues(array $reply): bool
    {
        $gs = (array) ($reply['gameState'] ?? []);

        return (int) ($gs['numberOfBonusRounds'] ?? 0) > 0
            || (int) ($gs['wonBonusLines'] ?? 0) > (int) ($gs['nowPlayedBonusLine'] ?? $gs['wonBonusLines'] ?? 0);
    }

    /** @return array{0: array<string, string>, 1: mixed} a frame's headers and decoded body */
    public static function parse(string $frame): array
    {
        [$head, $body] = array_pad(explode("\n\n", $frame, 2), 2, '');
        $h = [];
        foreach (explode("\n", $head) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
        }

        return [$h, json_decode($body, true)];
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function connect(): void
    {
        $this->close();
        $p = parse_url($this->socketUrl);
        $host = (string) ($p['host'] ?? '');
        $tls = ($p['scheme'] ?? 'https') !== 'http' && ($p['scheme'] ?? '') !== 'ws';
        $port = (int) ($p['port'] ?? ($tls ? 443 : 80));
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client(($tls ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$host}: {$error}");
        }
        $key = base64_encode(random_bytes(16));
        $path = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: https://static.joker-entry.link\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
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
                throw new RuntimeException('Apollo closed the session');
            }
            if ($opcode === 9 || $opcode === 10) {
                $fin = 0;   // a control frame between messages: read on

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
                throw new RuntimeException(stream_get_meta_data($this->socket)['timed_out'] ? 'Apollo timed out' : 'Apollo closed the session');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
