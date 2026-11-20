<?php

namespace App\Services\GamePlay\Novoline;

use App\Services\GamePlay\Support\WebSocketClient;
use RuntimeException;

/**
 * A bare client for Greentube's game server (Betboy's Novoline demos), used at
 * import time to read a game's login reply and record whole rounds for its
 * outcome library. Fun credits only.
 *
 * Wire: one WebSocket (`wss://<hostname>/mux?websocket=<port>_`, both off the
 * launch page's params), text frames: a type character, then fields split by
 * ÿ (U+00FF). Client → server:
 *
 *   !2.5.5ÿ1.10.25ÿ54   ' <id>ÿ   \x01 <room>ÿ<id>ÿ<game>ÿ1ÿ0     hello, login
 *   #0                  ready (no reply)
 *   2 <line bet>ÿ<lines>ÿ<x>  set the bet (no reply)
 *   30 spin   40 collect a win   60 a free spin   9 gamble   5r / 5b red / black
 *
 * Server → client: \x06 the whole game state (`S<balance>ÿ…ÿs,<state>ÿ…`), \x07
 * the account config, `%` and \x1f (the stake just taken) before a state. The
 * state's `s,` field drives the client: 1 idle, 11 a win to collect (or
 * gamble), 3 gambling, 5 free spins… Amounts are credits; the config's
 * CURRENCYFACTOR (100) credits make one unit of money.
 */
class NovolineLiveClient
{
    public const string SEP = "\u{FF}";

    private WebSocketClient $ws;

    /** @var list<string> the login reply: init state, account config */
    public array $login = [];

    /** The last state frame (\x06…). */
    public string $state = '';

    /**
     * What to send in a state the round is still in (learnt per game: the
     * first command the server answers with a state). 11 → collect, 5 → free spin.
     *
     * @var array<int, string>
     */
    public array $commands = [11 => '40', 5 => '60'];

    /** States the game rests in between rounds (most: 1; King of Cards: 0 after a losing spin). */
    public const array IDLE = [0, 1];

    /** Candidates tried for a state not yet known. */
    private const array TRY = ['60', '40', ':0', '70', '80', '61', '62', '63', '64', '65'];   // `:0`: a pick (Wild Horses' side)

    /**
     * States found to rest the game although not 0 / 1 (no command but a new,
     * paid spin moves them on — Magic 27's 15 after a feature).
     *
     * @var list<int>
     */
    public array $idle = [];

    public function __construct(private readonly string $url, private readonly string $room, private readonly string $game)
    {
        $this->ws = new WebSocketClient($url, timeout: 20);
    }

    /** From the launch page's HTML (its SLOT.start params). */
    public static function fromPage(string $html, string $game): self
    {
        preg_match('#"hostname":\s*"([^"]+)"#', $html, $h);
        preg_match('#"port":\s*"(\d+)"#', $html, $p);
        preg_match('#"roomid":\s*"(\d+)"#', $html, $r);
        if (! isset($h[1], $p[1], $r[1])) {
            throw new RuntimeException('Not a Greentube launch page (no hostname / port / roomid)');
        }

        return new self("wss://{$h[1]}/mux?websocket={$p[1]}_", $r[1], $game);
    }

    /** @return list<string> the login reply */
    public function open(): array
    {
        $this->ws->connect();
        $id = (string) random_int(1000, 1100);
        $this->ws->send('!2.5.5'.self::SEP.'1.10.25'.self::SEP.'54');
        $this->ws->send("'{$id}".self::SEP);
        $this->ws->send("\x01{$this->room}".self::SEP.$id.self::SEP.$this->game.self::SEP.'1'.self::SEP.'0');
        $this->login = [];
        for ($n = 0; count($this->login) < 2; $n++) {
            $f = $this->ws->receive();
            if ($f !== '' && ($f[0] === "\x06" || $f[0] === "\x07")) {
                $this->login[] = $f;
            }
            if ($n > 20) {
                throw new RuntimeException('Greentube sent no login reply');
            }
        }
        $this->state = (string) collect($this->login)->first(fn ($f) => $f[0] === "\x06");
        if (! str_contains($this->login[1] ?? '', 'SUCCESS') && ! str_contains($this->login[0], 'SUCCESS')) {
            throw new RuntimeException('Greentube refused the login: '.mb_strimwidth($this->login[1] ?? '', 0, 200));
        }
        $this->ws->send('#0');

        return $this->login;
    }

    public function close(): void
    {
        $this->ws->close();
    }

    /** Credits on the account. */
    public function balance(): int
    {
        return (int) self::balanceOf($this->state);
    }

    public function setBet(int $perLine, int $lines, string $x): void
    {
        $this->ws->send('2'.$perLine.self::SEP.$lines.self::SEP.$x);
    }

    /**
     * One whole round: the paid spin, then — as the client does — free spins
     * and collects until the game is idle again.
     *
     * @return array{start_credit: int, steps: list<array{c: string, r: list<string>}>, bet: int, win: int, feature: bool} credits
     */
    public function round(): array
    {
        $start = $this->balance();
        $steps = [$this->step('30')];
        $bet = self::stakeOf($steps[0]['r']);
        if ($bet <= 0) {
            throw new RuntimeException('The spin took no stake: '.mb_strimwidth(implode(' / ', $steps[0]['r']), 0, 300));
        }
        $feature = false;
        while (self::continues($this->state) && ! in_array(self::stateOf($this->state), $this->idle, true)) {
            $s = self::stateOf($this->state);
            if ($s !== 11) {
                $feature = true;
            }
            $step = $this->advance($s);
            if ($step === null) {
                break;
            }
            $steps[] = $step;
            if (count($steps) > 800) {
                throw new RuntimeException('Greentube round never ended (800 steps)');
            }
        }

        return ['start_credit' => $start, 'steps' => $steps, 'bet' => $bet, 'win' => $this->balance() - $start + $bet, 'feature' => $feature];
    }

    /** @return array{c: string, r: list<string>}|null null: the state turned out to be a resting one */
    private function advance(int $s): ?array
    {
        if (isset($this->commands[$s])) {
            return $this->step($this->commands[$s]);
        }
        // a feature's start screen (15) wants a pick — skipped, the demo server asks again after every collect
        $try = $s === 15 ? array_values(array_unique([':0', ...self::TRY])) : self::TRY;
        foreach ($try as $c) {   // a state not seen before: the command that moves the game on
            $before = $this->state;
            try {
                $step = $this->step($c, 6);
            } catch (RuntimeException) {
                continue;
            }
            if (! self::progressed($before, $this->state)) {   // answered, but nothing moved (Wild Horses' 15 takes `40` so)
                continue;
            }
            $this->commands[$s] = $c;

            return $step;
        }
        $this->idle[] = $s;   // only a new spin does: the game rests here

        return null;
    }

    /**
     * Send a command and read frames until a state arrives.
     *
     * @return array{c: string, r: list<string>}
     */
    private function step(string $command, int $timeout = 0): array
    {
        $this->ws->send($command);
        $frames = [];
        $deadline = microtime(true) + ($timeout ?: 30);
        while (true) {
            if ($timeout && microtime(true) > $deadline) {
                throw new RuntimeException("No state after {$command}");
            }
            $f = $this->ws->receive();
            $frames[] = $f;
            if ($f !== '' && $f[0] === "\x06") {
                $this->state = $f;

                return ['c' => $command, 'r' => $frames];
            }
            if ($f !== '' && $f[0] === 's') {   // "pin not allowed": the command doesn't fit the state
                throw new RuntimeException("Greentube refused {$command}: ".substr($f, 1));
            }
            if (count($frames) > 50) {
                throw new RuntimeException("No state after {$command}");
            }
        }
    }

    /**
     * Whether the round goes on after this state: not resting yet, or free
     * games left (`f,<total>,<played>,…`) — a free game without a win can
     * rest in 0 (Queen Cleopatra) like an idle game.
     */
    public static function continues(string $frame): bool
    {
        $f = self::field($frame, 'f');
        if ($f !== null && isset($f[1]) && (int) $f[1] < (int) $f[0]) {
            return true;
        }

        return ! in_array(self::stateOf($frame), self::IDLE, true);
    }

    /** Whether a reply moved the game on: another state, or one more free game played. */
    public static function progressed(string $before, string $after): bool
    {
        return self::stateOf($before) !== self::stateOf($after)
            || (self::field($before, 'f')[1] ?? null) !== (self::field($after, 'f')[1] ?? null);
    }

    /** @return list<string> a state frame's fields (type character stripped) */
    public static function fields(string $frame): array
    {
        return explode(self::SEP, substr($frame, 1));
    }

    public static function stateOf(string $frame): int
    {
        foreach (self::fields($frame) as $f) {
            if (str_starts_with($f, 's,')) {
                return (int) substr($f, 2);
            }
        }

        return 1;
    }

    public static function balanceOf(string $frame): int
    {
        $f = self::fields($frame)[0] ?? '';

        return str_starts_with($f, 'S') ? (int) substr($f, 1) : 0;
    }

    /** @param list<string> $frames the stake frame's (\x1f) amount */
    public static function stakeOf(array $frames): int
    {
        foreach ($frames as $f) {
            if ($f !== '' && $f[0] === "\x1f") {
                return (int) substr($f, 1);
            }
        }

        return 0;
    }

    /** A state field's key: `l` of `l,A,3,…`, `S` of `S9990`, `I` of `I10`, `X` of `X`. */
    public static function key(string $field): string
    {
        if (str_contains($field, ',')) {
            return strstr($field, ',', true);
        }

        return preg_match('/^([A-Za-z])\d+$/', $field, $m) ? $m[1] : $field;
    }

    /** A `key,v1,v2…` field's values (`:b`, `:m`, `e`…), or null. @return list<string>|null */
    public static function field(string $frame, string $key): ?array
    {
        foreach (self::fields($frame) as $f) {
            if (str_starts_with($f, $key.',')) {
                return explode(',', substr($f, strlen($key) + 1));
            }
        }

        return null;
    }
}
