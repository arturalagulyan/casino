<?php

namespace App\Services\GamePlay\Support;

use RuntimeException;

/**
 * A minimal blocking WebSocket client (RFC 6455, text frames) for import-time
 * recording against provider demo servers.
 */
class WebSocketClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private readonly string $url, private readonly string $origin = 'https://static.joker-entry.link', private readonly int $timeout = 30) {}

    public function connect(): void
    {
        $this->close();
        $p = parse_url($this->url);
        $host = (string) ($p['host'] ?? '');
        $tls = in_array($p['scheme'] ?? 'wss', ['wss', 'https'], true);
        $port = (int) ($p['port'] ?? ($tls ? 443 : 80));
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client(($tls ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$host}: {$error}");
        }
        $key = base64_encode(random_bytes(16));
        $path = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$this->origin}\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
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
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    public function connected(): bool
    {
        return $this->socket !== null;
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function send(string $message): void
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

    /** The next whole message (control frames skipped). */
    public function receive(): string
    {
        $message = '';
        do {
            $h = $this->read(2);
            $fin = ord($h[0]) & 0x80;
            $opcode = ord($h[0]) & 0x0F;
            $len = ord($h[1]) & 0x7F;
            if ($len === 126) {
                $len = unpack('n', $this->read(2))[1];
            } elseif ($len === 127) {
                $len = unpack('J', $this->read(8))[1];
            }
            $data = $len ? $this->read($len) : '';
            if ($opcode === 8) {
                throw new RuntimeException('The server closed the WebSocket');
            }
            if ($opcode === 9 || $opcode === 10) {
                $fin = 0;

                continue;
            }
            $message .= $data;
        } while (! $fin);

        return $message;
    }

    private function read(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->socket, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                throw new RuntimeException(stream_get_meta_data($this->socket)['timed_out'] ? 'WebSocket timed out' : 'The server closed the WebSocket');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
