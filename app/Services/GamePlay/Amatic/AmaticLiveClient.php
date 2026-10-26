<?php

namespace App\Services\GamePlay\Amatic;

use RuntimeException;

/**
 * A bare WebSocket client for Amatic's own free-play game server (the
 * `Config.value6` endpoint the CDN clients talk to). Used at import time to
 * read a game's real reel strips and sample real spins, so the maths we
 * build can be checked against what Amatic actually pays.
 *
 * Free-play only: the session is `freeplay`, no money is involved. Spins are
 * paced — Amatic drops connections that spin faster than a client could.
 */
class AmaticLiveClient
{
    public const string DEFAULT_SERVER = 'wss://grandxnewgames.amatic.com/games';

    /** @var resource|null */
    private $socket = null;

    private ?string $gameId = null;

    private string $currency = 'EUR';

    public function __construct(private readonly string $url = self::DEFAULT_SERVER) {}

    /** Open a session for `$gameId` (the engine's own id, e.g. `BlazingCoins100`) and return its init frame. */
    public function init(string $gameId, string $currency = 'EUR'): string
    {
        [$this->gameId, $this->currency] = [$gameId, $currency];
        $this->connect();
        $this->write("A/u25freeplay,freeplay,,{$gameId},2_0_0,,{$currency},2|15|0|en-US |1920|1080|1");
        for ($k = 0; $k < 5; $k++) {
            $frame = $this->read();
            if (str_contains($frame, 'authfailed')) {
                throw new RuntimeException("Amatic refused game id '{$gameId}'");
            }
            if (strlen($frame) > 40) {
                return $frame;
            }
        }

        throw new RuntimeException('Amatic sent no init frame');
    }

    /** Send one command and return its reply (skipping keep-alive / balance pushes). */
    public function send(string $command): string
    {
        $this->write($command);
        for ($k = 0; $k < 5; $k++) {
            $frame = $this->read();
            if (! preg_match('/^(9freeplay|UPDATE#)/', $frame)) {
                return $frame;
            }
        }

        return '';
    }

    /**
     * Up to `$count` base-game spins at the lowest bet. Stops early (keeping
     * what it has) when Amatic closes the session, e.g. on a feature the
     * plain spin command can't continue.
     *
     * @return list<string>
     */
    public function spins(int $lines, int $count, int $paceMs = 1200): array
    {
        $frames = [];
        $reconnects = 0;
        while (count($frames) < $count) {
            try {
                usleep($paceMs * 1000);
                $frame = $this->send("A/u251,{$lines},0,1,-1");
                // a spin answer carrying a message code instead (e.g. the
                // session-time notice) — this session is done spinning
                if (preg_match('/^(?:-\d)?1[0-9a-f]{2}([1-9a-f])/', $frame)) {
                    throw new RuntimeException('session message');
                }
                $frames[] = $frame;
            } catch (RuntimeException) {
                // Amatic ends the session e.g. when a feature starts (the
                // plain spin can't continue it) — a fresh one is a base game again
                if (++$reconnects > 8 || $this->gameId === null) {
                    break;
                }
                try {
                    $this->init($this->gameId, $this->currency);
                } catch (RuntimeException) {
                    break;
                }
            }
        }

        return $frames;
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
        $p = parse_url($this->url);
        $host = (string) ($p['host'] ?? '');
        $tls = ($p['scheme'] ?? 'wss') === 'wss';
        $port = $p['port'] ?? ($tls ? 443 : 80);
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client(($tls ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$this->url}: {$error}");
        }
        $key = base64_encode(random_bytes(16));
        fwrite($socket, 'GET '.($p['path'] ?? '/')." HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: https://cdn02.cdn.amatic.com\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
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
        stream_set_timeout($socket, 20);
        $this->socket = $socket;
    }

    private function write(string $message): void
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

    private function read(): string
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
                throw new RuntimeException('Amatic closed the session');
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
                throw new RuntimeException(stream_get_meta_data($this->socket)['timed_out'] ? 'Amatic timed out' : 'Amatic closed the session');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
