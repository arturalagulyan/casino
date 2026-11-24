<?php

namespace App\Services\GamePlay\PragmaticPlay;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for Pragmatic Play's game service as Betboy runs it
 * (`https://<session>.joker-entry.link/skvCore/WebEngine.php?/gameService`),
 * used at import time to read a game's setup and record whole rounds for its
 * outcome library. Fun credits only (Betboy's demo: blank currency).
 *
 * Wire ("gs2c"): `POST <gameService>` with a form body
 * `action=<doInit|doSpin|doCollect|…>&symbol=<game>&index=<n>&counter=<n>&repeat=0&mgckey=<session>[&c=<coin>&l=<lines>…]`
 * → a flat `key=value&key=value` body (values unescaped: `~ , ; [ ] { } "` appear raw).
 *
 * `na` in every reply names the action the server waits for next:
 * `s` spin (a new paid spin — or, inside a round, the next free spin / tumble /
 * respin, which costs nothing), `c` collect (doCollect), `cb` collect a bonus
 * (doCollectBonus), `b` a bonus step (doBonus, with `ind` for a pick),
 * `fso` a free-spin option (doFSOption, `ind`), `m` a mystery scatter
 * (doMysteryScatter). A round is over when `na=s` and nothing of it is left to
 * play ({@see self::inRound()}).
 */
class PragmaticLiveClient
{
    private int $index = 1;

    private int $counter = 1;

    /** @var array<string, string> the doInit reply */
    public array $init = [];

    /** @var array<string, string> the last reply */
    public array $last = [];

    /** what the next doBonus / doFSOption picks: an index chooser, else random */
    public ?\Closure $chooser = null;

    public function __construct(public readonly string $service, public readonly string $key, public readonly string $symbol) {}

    /** From Betboy's launch URL (its page carries Html5GameManager.init({gameConfig})). */
    public static function fromPage(string $url): self
    {
        return self::fromLaunchHtml(Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 2000)->get($url)->body());
    }

    public static function fromLaunchHtml(string $html): self
    {
        $cfg = self::gameConfig($html);
        if (trim((string) ($cfg['currency'] ?? 'x')) !== '') {
            throw new RuntimeException('Pragmatic launch is not a demo session (currency '.json_encode($cfg['currency'] ?? null).') — refusing to play it');
        }
        $symbol = basename(rtrim((string) $cfg['datapath'], '/'));

        return new self((string) $cfg['gameService'].'gameService', (string) $cfg['mgckey'], $symbol);
    }

    /** @return array<string, mixed> the launch page's gameConfig */
    public static function gameConfig(string $html): array
    {
        if (! preg_match("#gameConfig:\s*'(\{.*?\})',\s*\n#s", $html, $m) || ! is_array($cfg = json_decode($m[1], true))
            || ! isset($cfg['gameService'], $cfg['mgckey'], $cfg['datapath'])) {
            throw new RuntimeException('Not a Pragmatic launch page (no Html5GameManager gameConfig)');
        }

        return $cfg;
    }

    /** @return array<string, string> the doInit reply (a round left in play by an earlier session is played out first) */
    public function open(): array
    {
        $this->init = $this->call('doInit', ['cver' => '1']);
        if (! isset($this->init['balance'], $this->init['na'])) {
            throw new RuntimeException('Pragmatic doInit sent no balance: '.mb_strimwidth(self::encode($this->init), 0, 300));
        }
        $guard = 0;
        while (self::inRound($this->last) && $guard++ < 500) {
            $this->step($this->last, (float) ($this->init['c'] ?? $this->init['defc'] ?? 0), (int) ($this->init['l'] ?? 1));
        }

        return $this->init;
    }

    public function credit(): float
    {
        return (float) ($this->last['balance'] ?? 0);
    }

    /**
     * One whole round: the paid doSpin, then whatever the server asks for
     * until it waits for the next paid spin.
     *
     * @param  array<string, string|int|float>  $extra  more spin parameters (bl=0 …)
     * @return array{start_credit: float, steps: list<array{action: string, params: array<string, mixed>, reply: array<string, string>}>}
     */
    public function round(float $coin, int $lines, array $extra = []): array
    {
        $start = $this->credit();
        $params = ['c' => self::num($coin), 'l' => $lines] + $extra;
        $reply = $this->call('doSpin', $params);
        $steps = [['action' => 'doSpin', 'params' => $params, 'reply' => $reply]];
        while (self::inRound($reply)) {
            [$action, $p] = $this->next($reply, $coin, $lines, $extra);
            $reply = $this->call($action, $p);
            $steps[] = ['action' => $action, 'params' => $p, 'reply' => $reply];
            if (count($steps) > 600) {
                throw new RuntimeException('Pragmatic round never ended (600 steps)');
            }
        }

        return ['start_credit' => $start, 'steps' => $steps];
    }

    /** @param array<string, string> $reply */
    private function step(array $reply, float $coin, int $lines): void
    {
        [$action, $p] = $this->next($reply, $coin, $lines, []);
        $this->call($action, $p);
    }

    /**
     * The request the server waits for after `$reply`.
     *
     * @param  array<string, string>  $reply
     * @param  array<string, mixed>  $extra
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function next(array $reply, float $coin, int $lines, array $extra): array
    {
        $na = (string) ($reply['na'] ?? 's');

        return match ($na) {
            's' => ['doSpin', ['c' => self::num($coin), 'l' => $lines] + $extra],
            'c' => ['doCollect', []],
            'cb' => ['doCollectBonus', []],
            'b' => ['doBonus', ['ind' => $this->choose($reply, 'b')]],
            'fso' => ['doFSOption', ['ind' => $this->choose($reply, 'fso')]],
            'm' => ['doMysteryScatter', []],
            'g' => ['doGambleOption', ['g_a' => 'stand', 'g_o_ind' => -1]],
            default => throw new RuntimeException("Pragmatic asks for an unknown next action na={$na}"),
        };
    }

    /**
     * Whether `$reply` leaves the round going: anything but a spin asked for,
     * or a spin that is a free spin / tumble / respin of this round.
     *
     * @param  array<string, string>  $reply
     */
    public static function inRound(array $reply): bool
    {
        if ($reply === []) {
            return false;
        }
        if (($reply['na'] ?? 's') !== 's') {
            return true;
        }
        // a win still to collect (a tumble's running total), free spins left, a respin running
        if ((float) ($reply['tw'] ?? 0) > 0) {
            return true;
        }
        if (isset($reply['fs']) && ! isset($reply['fs_total'])) {
            return true;
        }

        return isset($reply['rs']) || isset($reply['rs_p']);
    }

    /** @param array<string, string> $reply */
    private function choose(array $reply, string $kind): int
    {
        if ($this->chooser) {
            return (int) ($this->chooser)($reply, $kind);
        }
        // a pick among `status` slots still closed (0), else among the options offered
        if (isset($reply['status'])) {
            $closed = array_keys(array_filter(explode(',', (string) $reply['status']), fn ($s) => $s === '0'));
            if ($closed !== []) {
                return (int) $closed[array_rand($closed)];
            }
        }
        // free-spin options: `fs_opt` = one `~`-separated entry per option
        if (isset($reply['fs_opt'])) {
            return random_int(0, max(0, count(explode('~', (string) $reply['fs_opt'])) - 1));
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function call(string $action, array $params = []): array
    {
        $body = ['action' => $action, 'symbol' => $this->symbol] + $params
            + ['index' => $this->index, 'counter' => $this->counter, 'repeat' => 0, 'mgckey' => $this->key];
        $r = $this->http()->asForm()->post($this->service, $body);
        $text = trim($r->body());
        if (! $r->successful() || $text === '') {
            throw new RuntimeException("Pragmatic {$action}: HTTP {$r->status()} ".mb_strimwidth($text, 0, 300));
        }
        $reply = self::parse($text);
        if (! isset($reply['na']) && ! isset($reply['balance'])) {
            throw new RuntimeException("Pragmatic {$action}: ".mb_strimwidth($text, 0, 300));
        }
        $this->index = (int) ($reply['index'] ?? $this->index) + 1;
        $this->counter = (int) ($reply['counter'] ?? $this->counter) + 1;

        return $this->last = $reply;
    }

    /** @return array<string, string> */
    public static function parse(string $body): array
    {
        $out = [];
        foreach (explode('&', $body) as $part) {
            if ($part === '') {
                continue;
            }
            $kv = explode('=', $part, 2);
            $out[$kv[0]] = $kv[1] ?? '';
        }

        return $out;
    }

    /** @param array<string, string|int|float> $reply */
    public static function encode(array $reply): string
    {
        $parts = [];
        foreach ($reply as $k => $v) {
            $parts[] = $k.'='.$v;
        }

        return implode('&', $parts);
    }

    public static function num(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent('Mozilla/5.0')->withHeaders(['Origin' => 'https://static.joker-entry.link'])
            ->timeout(30)->retry(2, 2000, throw: false);
    }
}
