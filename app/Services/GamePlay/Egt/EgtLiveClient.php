<?php

namespace App\Services\GamePlay\Egt;

use App\Services\GamePlay\Support\WebSocketClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for EGT's demo server (Betboy), used at import time to read a
 * game's settings and record whole rounds for its outcome library. Fun
 * credits only (currency NAN).
 *
 * Wire: one WebSocket (`wss://<tcpHost>:<tcpPort>?sessionKey=…`, as the
 * client builds it from the launch page's connectionParams), JSON text frames
 * `{command, …, messageId}` answered by `{…, command, msg: "success"}`:
 *
 *   login → settings (bets, paytable, reels) → subscribe (current state)
 *   bet {bet: {bet, betMultiplier, bonus, gameCommand: bet|collect|gamble|…}}
 *
 * plus the client's bare `ping` (answered with a ping message). Money in cents.
 */
class EgtLiveClient
{
    private WebSocketClient $ws;

    private int $sequence = 0;

    /** @var array<string, mixed> */
    public array $login = [];

    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var array<string, mixed> */
    public array $subscribe = [];

    public int $balance = 0;

    public string|int $gameNumber = -1;

    /** The state the server last reported (idle, gamble, freespin, …). */
    public string $state = 'idle';

    /** @param  array<string, mixed>  $params  the launch page's connectionParams */
    public function __construct(public readonly array $params)
    {
        $host = (string) $params['tcpHost'];
        $url = ($params['tcpProtocol'] ?? 'wss').'://'.$host.':'.($params['tcpPort'] ?? '443').'?sessionKey='.$params['sessionKey'];
        $this->ws = new WebSocketClient($url, 'https://static.joker-entry.link', 40);
    }

    /** The launch page (Betboy's `joker-entry.link` page) → its connectionParams. */
    public static function fromPage(string $page): self
    {
        $html = Http::withUserAgent('Mozilla/5.0')->timeout(30)->get($page)->body();

        return new self(self::params($html));
    }

    /** @return array<string, mixed> */
    public static function params(string $html): array
    {
        if (! preg_match('/var connectionParams = (\{.*?\});\s*\n\s*var additionalParams/s', $html, $m)) {
            throw new RuntimeException('Not an EGT launch page (no connectionParams)');
        }
        $js = $m[1];
        // a JS object literal: some keys unquoted, some strings single-quoted
        $js = (string) preg_replace("/([{,]\s*)([A-Za-z_]\w*)\s*:/", '$1"$2":', $js);
        $js = (string) preg_replace_callback("/'([^'\\\\]*)'/", fn ($x) => json_encode($x[1]), $js);
        $p = json_decode($js, true);
        if (! is_array($p) || empty($p['tcpHost']) || empty($p['gameIdentificationNumber'])) {
            throw new RuntimeException('EGT connectionParams unreadable: '.mb_strimwidth($m[1], 0, 200));
        }

        return $p;
    }

    public function gin(): int
    {
        return (int) $this->params['gameIdentificationNumber'];
    }

    /** login, settings, subscribe — refuses anything but a fun-credit session. */
    public function open(): void
    {
        $this->ws->connect();
        $this->login = $this->send(['command' => 'login', 'platformType' => 'desktop', 'gameIdentificationNumber' => $this->gin()]);
        if (($this->login['currency'] ?? '') !== 'NAN') {
            $this->close();
            throw new RuntimeException('EGT opened a session in '.($this->login['currency'] ?? '?').', not a demo — refusing to stake real money.');
        }
        $this->balance = (int) ($this->login['balance'] ?? 0);
        $this->settings = $this->send(['command' => 'settings', 'gameIdentificationNumber' => $this->gin(), 'gameNumber' => -1]);
        $this->subscribe = $this->send(['command' => 'subscribe', 'gameIdentificationNumber' => $this->gin(), 'gameNumber' => -1]);
        $this->state = (string) ($this->subscribe['complex']['currentState']['state'] ?? 'idle');
    }

    /**
     * One `bet` command (any gameCommand).
     *
     * @param  array<string, mixed>  $bet
     * @return array<string, mixed>
     */
    public function bet(array $bet): array
    {
        $reply = $this->send(['command' => 'bet', 'gameIdentificationNumber' => $this->gin(), 'bet' => $bet, 'gameNumber' => $this->gameNumber]);
        if (isset($reply['balance'])) {
            $this->balance = (int) $reply['balance'];
        }
        if (isset($reply['gameNumber'])) {
            $this->gameNumber = $reply['gameNumber'];
        }
        if (isset($reply['state'])) {
            $this->state = (string) $reply['state'];
        }

        return $reply;
    }

    /**
     * One whole round at `$bet` (cents): the paid spin, then — as the client
     * does — one more `bet` per free spin while the server stays in a
     * feature state, and the win collected (no gamble).
     *
     * @return array{start_balance: int, end_balance: int, steps: list<array<string, mixed>>}
     */
    public function round(int $bet): array
    {
        if ($this->state !== 'idle') {
            throw new RuntimeException("EGT session not idle ({$this->state})");
        }
        $start = $this->balance;
        $steps = [];
        $command = 'bet';
        do {
            $reply = $this->bet(['bet' => $bet, 'betMultiplier' => 1, 'bonus' => false, 'gameCommand' => $command, 'featureId' => 0]);
            if (! isset($reply['complex']) || ! is_array($reply['complex'])) {
                throw new RuntimeException('EGT bet without a result: '.mb_strimwidth(json_encode($reply) ?: '', 0, 300));
            }
            $steps[] = $reply;
            if (count($steps) > 500) {
                throw new RuntimeException('EGT round never ended (500 steps)');
            }
            $command = self::next($this->state);
        } while ($command !== null);
        if ($this->state === 'gamble') {
            $this->bet(['bet' => $bet, 'betMultiplier' => 1, 'bonus' => false, 'gameCommand' => 'collect', 'featureId' => 0]);
        }
        if ($this->state !== 'idle') {
            throw new RuntimeException("EGT round left the session in state {$this->state}");
        }

        return ['start_balance' => $start, 'end_balance' => $this->balance, 'steps' => $steps];
    }

    /** The gameCommand the client sends next in `$state`; null: the round is over (idle, or a win to collect / gamble). */
    public static function next(string $state): ?string
    {
        return match ($state) {
            'idle', 'gamble' => null,
            'freespin' => 'bet',
            default => throw new RuntimeException("EGT state '{$state}' not handled by the recorder"),
        };
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function send(array $message): array
    {
        $message['sessionKey'] = $this->params['sessionKey'];
        $message['messageId'] = 'r-'.bin2hex(random_bytes(8)).'_'.(++$this->sequence);
        $this->ws->send(json_encode($message, JSON_UNESCAPED_SLASHES));
        for ($k = 0; $k < 50; $k++) {
            $raw = $this->ws->receive();
            $reply = json_decode($raw, true);
            if (! is_array($reply) || ($reply['messageId'] ?? null) !== $message['messageId']) {
                continue;   // pushes (jackpots, pings)
            }
            if (($reply['msg'] ?? '') !== 'success') {
                throw new RuntimeException("EGT {$message['command']}: ".mb_strimwidth($raw, 0, 300));
            }

            return $reply;
        }
        throw new RuntimeException("EGT sent no reply to {$message['command']}");
    }

    public function close(): void
    {
        $this->ws->close();
    }
}
