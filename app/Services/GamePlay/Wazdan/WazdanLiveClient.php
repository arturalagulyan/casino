<?php

namespace App\Services\GamePlay\Wazdan;

use RuntimeException;

/**
 * A bare client for Wazdan's RGS behind Betboy, used at import time to read a
 * game's setup and record whole rounds for its outcome library. Demo sessions
 * only (Betboy `isDemo`, fun credits).
 *
 * Wire: one WebSocket to the launch page's `host` (…/wz/ws/), binary frames
 * holding the upper-case hex of AES-128-CBC ciphertext — key = IV = the
 * client's built-in default "1234567812345678" (the launch's `k`/`i` are
 * empty), zero padded. Plain text is JSON:
 *
 *   {"b":"<8 hex>", "service":"game", "act":"START", "arg":{"cn":9, "stake":10}, "ts":…, "id":10}
 *   → {"a":"Ts2vn5AY","id":10,"res":{…},"s":"ok"}
 *
 * `b` is a client checksum the server doesn't check; `a` a per-reply tag the
 * client doesn't check. Money is in cents.
 */
class WazdanLiveClient
{
    public const string KEY = '1234567812345678';

    /** @var resource|null */
    private $socket = null;

    private int $id = 0;

    private int $cn = 0;

    /** @var array<string, string> the launch page's SID2 parameters */
    public array $params = [];

    public string $sid = '';

    /** @var array<string, array<string, mixed>> setup replies by act: GAME_PARAMS, GAME, STATE, LIST_PARAMS, LOAD_CLDATA_WEB, login */
    public array $init = [];

    public int $credit = 0;

    public function __construct(string $sid)
    {
        $this->sid = $sid;
        $this->params = self::params($sid);
        if (($this->params['host'] ?? '') === '' || ($this->params['cmd'] ?? '') === '') {
            throw new RuntimeException('Not a Wazdan launch (no host/cmd in SID2): '.mb_strimwidth($sid, 0, 200));
        }
    }

    /** The launch page's `var SID2 = [char codes]`. */
    public static function fromPage(string $html): self
    {
        if (! preg_match('/var SID2 = \[([^\]]*)\]/', $html, $m)) {
            throw new RuntimeException('Not a Wazdan launch page (no SID2)');
        }

        return new self(self::sidFromCodes($m[1]));
    }

    public static function sidFromCodes(string $codes): string
    {
        return implode('', array_map('chr', array_filter(array_map('intval', explode(',', $codes)))));
    }

    /** `lang=en;host=…;cmd=…;…;gparams={json};` → [name => value] */
    public static function params(string $sid): array
    {
        $out = [];
        [$head, $gparams] = array_pad(explode(';gparams=', $sid, 2), 2, null);
        foreach (explode(';', $head) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $out[$k] = $v;
            }
        }
        if ($gparams !== null) {
            $out['gparams'] = rtrim(rtrim($gparams, "\0"), ';');
        }

        return $out;
    }

    public static function encrypt(string $plain): string
    {
        $pad = (16 - strlen($plain) % 16) % 16;

        return strtoupper(bin2hex((string) openssl_encrypt($plain.str_repeat("\0", $pad), 'aes-128-cbc', self::KEY,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, self::KEY)));
    }

    public static function decrypt(string $hex): string
    {
        $raw = @hex2bin(trim($hex));
        if ($raw === false || $raw === '' || strlen($raw) % 16 !== 0) {
            throw new RuntimeException('Not a Wazdan frame: '.mb_strimwidth($hex, 0, 80));
        }

        return rtrim((string) openssl_decrypt($raw, 'aes-128-cbc', self::KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, self::KEY), "\0");
    }

    /** @return array<string, array<string, mixed>> the setup replies */
    public function open(): array
    {
        $this->connect();
        $this->id = $this->cn = 0;
        $this->call('wsp', 'setup', ['cid' => self::cid(), 'ver' => 4, 'lc' => $this->params['cmd'],
            'session' => $this->params['session'] ?? '', 'environment' => 'browser', 'pingTimeout' => 30], false);
        $this->init['login'] = $this->call('game', $this->params['cmd'], ['media' => 'web', 'platform' => 'desktop',
            'environment' => 'browser', 'version' => '2025.13.2 (375)', 'pv' => '4', 'features' => '1', 'sid' => $this->sid,
            'session' => $this->params['session'] ?? '', 'game_id' => '0x0']);
        $this->init['LOAD_CLDATA_WEB'] = $this->call('game', 'LOAD_CLDATA_WEB', ['param' => 'currency_display_as,show_currency,currency,screen_orientation_type,currency_format_separ_type,currency_format_symbol_placement,currency_format_show_decimals,screenShotUrl,screenShotType,screenShotMinPrize,screenShotMaxSize']);
        $this->init['LIST_PARAMS'] = $this->call('game', 'LIST_PARAMS', ['id' => '0x0']);
        $this->init['GAME_PARAMS'] = $this->call('game', 'GAME_PARAMS', ['id' => '0x0']);
        $this->init['GAME'] = $this->call('game', 'GAME', ['id' => '0x0', 'help_version' => '4.3.11.6']);
        $this->init['STATE'] = $this->call('game', 'STATE', []);
        $this->call('game', 'READY_TO_PLAY', []);
        $this->credit = (int) ($this->init['GAME']['credit'] ?? 0);
        // a demo session has no currency; a real-money one would carry its code
        if (trim((string) ($this->init['LOAD_CLDATA_WEB']['currency'] ?? '')) !== '') {
            $this->close();
            throw new RuntimeException("Wazdan opened a session in {$this->init['LOAD_CLDATA_WEB']['currency']}, not a demo — refusing to stake real money.");
        }

        return $this->init;
    }

    /**
     * Send one request and return its reply's `res`. A reply other than "ok"
     * throws, with the server's message.
     *
     * @param  array<string, mixed>  $arg
     * @return array<string, mixed>
     */
    public function call(string $service, string $act, array $arg, bool $counted = true): array
    {
        if ($counted) {
            $arg = ['cn' => ++$this->cn] + $arg;
        }
        $msg = '{"b":"'.sprintf('%08x', random_int(0, 0x7FFFFFFF)).'", "service":"'.$service.'", "act":"'.$act.'", "arg":'
            .json_encode((object) $arg, JSON_UNESCAPED_SLASHES).', "ts":'.intdiv(hrtime(true), 1000).', "id":'.++$this->id.'}';
        $this->frame(self::encrypt($msg));
        for ($k = 0; $k < 50; $k++) {
            $reply = json_decode(self::decrypt($this->readFrame()), true);
            if (! is_array($reply) || ($reply['id'] ?? null) !== $this->id) {
                continue;   // a push of its own
            }
            if (($reply['s'] ?? '') !== 'ok') {
                throw new WazdanError($act, $reply);
            }
            $res = (array) ($reply['res'] ?? []);
            if (isset($res['credit']) && is_numeric($res['credit'])) {
                $this->credit = (int) $res['credit'];
            }

            return $res;
        }
        throw new RuntimeException("Wazdan sent no reply to {$act}");
    }

    /**
     * One whole round, as the client plays it: the paid START, one more START
     * (no stake taken) per free game / respin left, then the win banked.
     *
     * @param  array<string, mixed>  $arg  the START arguments (stake, coins)
     * @return array{start_credit: int, end_credit: int, steps: list<array<string, mixed>>} credit in cents
     */
    public function round(array $arg): array
    {
        $start = $this->credit;
        $steps = [];
        $counter = false;
        do {
            $res = $this->call('game', 'START', $arg);
            $steps[] = $res;
            if (count($steps) > 500) {
                throw new RuntimeException('Wazdan round never ended (500 STARTs)');
            }
        } while (self::continues($res, $counter));
        if ((int) ($res['prize'] ?? 0) > 0) {
            $this->call('game', 'PRIZE_TO_BANK', []);
        }

        return ['start_credit' => $start, 'end_credit' => $this->credit, 'steps' => $steps];
    }

    /**
     * Whether the client plays on, unpaid, after this reply. Two kinds of
     * feature: free games counting `bonus` (games left) down — Book of Magic,
     * Triple Star, whose last respin resets `bonus_nr` to 0 — and Hold & Win
     * respins counting `bonus_nr` up to `bonus_count` (`bonus` stays 0 or
     * absent) — Fortune Reels, Sun of Fortune. A round is in the first kind
     * once a reply has had `bonus` > 0 ($counter, carried across the round).
     * Playing on when the round is over (or banking before it is) gets no
     * reply at all from the server.
     */
    public static function continues(array $res, bool &$counter = false): bool
    {
        $counter = $counter || (int) ($res['bonus'] ?? 0) > 0;

        return $counter ? (int) ($res['bonus'] ?? 0) > 0 : (int) ($res['bonus_count'] ?? 0) > (int) ($res['bonus_nr'] ?? 0);
    }

    public function ping(): void
    {
        $this->call('wsp', 'ping', [], false);
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private static function cid(): string
    {
        $abc = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        return implode('', array_map(fn () => $abc[random_int(0, 61)], range(1, 8)));
    }

    private function connect(): void
    {
        $this->close();
        [$host, $path] = array_pad(explode('/', $this->params['host'], 2), 2, '');
        $port = (int) ($this->params['port'] ?? 443);
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client(($port === 443 ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$host}: {$error}");
        }
        fwrite($socket, "GET /{$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            .'Sec-WebSocket-Key: '.base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\nOrigin: https://{$host}\r\nUser-Agent: Mozilla/5.0\r\n\r\n");
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
        $head = chr(0x82).match (true) {
            $len < 126 => chr(0x80 | $len),
            $len < 65536 => chr(0x80 | 126).pack('n', $len),
            default => chr(0x80 | 127).pack('J', $len),
        };
        fwrite($this->socket, $head.$mask.($message ^ str_repeat($mask, intdiv($len, 4) + 1)));
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
                throw new RuntimeException('Wazdan closed the session');
            }
            if ($opcode === 9 || $opcode === 10) {
                $fin = 0;

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
                throw new RuntimeException(stream_get_meta_data($this->socket)['timed_out'] ? 'Wazdan timed out' : 'Wazdan closed the session');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
