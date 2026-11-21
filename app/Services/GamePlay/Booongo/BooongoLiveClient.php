<?php

namespace App\Services\GamePlay\Booongo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for Booongo's game server (Betboy's `…/skvCore/WebEngine.php`),
 * used at import time to read a game's settings and record whole rounds for
 * its outcome library. Fun credits only (Betboy's demo: `wl: demo`, currency FUN).
 *
 * Wire: `POST {server}?gsc=<command>` with a JSON body
 * `{command, request_id, session_id?, …}` → JSON:
 *
 *   login  {token, language}            session_id, user.balance (cents)
 *   start  {mode: "play", huid}         settings (bets, lines, bet_factor, paytable…), context
 *   play   {action: {name, params}}     context: `actions` the client may send next,
 *                                       `round_finished`, `current` (spins / freespins / bonus…)
 *   sync                                user + context (keep-alive)
 *
 * A round starts with `spin` {bet_per_line, lines} and runs on while the
 * server offers anything but a spin (`freespin_init`, `freespin`,
 * `freespin_stop`, `bonus_init`, `respin`…), each sent with no params.
 * The stake is `bet_per_line × bet_factor` cents; the balance moves as wins land.
 */
class BooongoLiveClient
{
    private ?string $session = null;

    /** @var array<string, mixed> the start reply */
    public array $start = [];

    /** @var array<string, mixed> the last reply */
    public array $last = [];

    public function __construct(private readonly string $server, private readonly string $token) {}

    /** From Betboy's launch URL (its page holds the game runner's `server_url` and `options.token`). */
    public static function fromPage(string $url): self
    {
        return self::fromLaunchHtml(Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 2000)->get($url)->body());
    }

    /** From Betboy's launch page (the game runner's `server_url` and `options.token`). */
    public static function fromLaunchHtml(string $html): self
    {
        if (! preg_match("#server_url:\s*'([^']+WebEngine\.php)'#", $html, $s) || ! preg_match('#"token":\s*"([^"]+)"#', $html, $t)) {
            throw new RuntimeException('Not a Booongo launch page (no WebEngine server_url / token)');
        }
        if (! preg_match('#"wl":\s*"demo"#', $html)) {
            throw new RuntimeException('Booongo launch is not a demo session — refusing to play it');
        }

        return new self($s[1], $t[1]);
    }

    /** @return array<string, mixed> the start reply (settings + context) */
    public function open(): array
    {
        $login = $this->call('login', ['token' => $this->token, 'language' => 'en']);
        if (strtoupper((string) ($login['user']['currency'] ?? '')) !== 'FUN') {
            throw new RuntimeException('Booongo session is not in fun credits (currency '.json_encode($login['user']['currency'] ?? null).') — refusing to play it');
        }
        $this->start = $this->call('start', ['mode' => 'play', 'huid' => (string) ($login['user']['huid'] ?? 'huid')]);
        if (! isset($this->start['settings'], $this->start['context'])) {
            throw new RuntimeException('Booongo start sent no settings: '.mb_strimwidth(json_encode($this->start) ?: '', 0, 300));
        }
        // a round left in play by an earlier session: play it out first
        $guard = 0;
        while (! self::idle($this->last) && $guard++ < 500) {
            $this->act($this->nextAction($this->last));
        }

        return $this->start;
    }

    public function credit(): int
    {
        return (int) ($this->last['user']['balance'] ?? 0);
    }

    /** The smallest line bet — what rounds are recorded at. */
    public function minBet(): float
    {
        return (float) min((array) ($this->start['settings']['bets'] ?? [1]));
    }

    public function lines(): int
    {
        return (int) max((array) ($this->start['settings']['lines'] ?? [1]) ?: [1]);
    }

    /**
     * One whole round: the paid `spin`, then whatever the server asks for
     * until it is ready for the next spin.
     *
     * @return array{start_credit: int, steps: list<array<string, mixed>>, actions: list<string>}
     */
    public function round(float $betPerLine, int $lines): array
    {
        $start = $this->credit();
        $steps = [];
        $actions = [];
        $reply = $this->act('spin', ['bet_per_line' => $betPerLine == floor($betPerLine) ? (int) $betPerLine : $betPerLine, 'lines' => $lines]);
        $steps[] = $reply;
        $actions[] = 'spin';
        while (! self::idle($reply)) {
            $name = $this->nextAction($reply);
            // a pick (African Spirit's free-spin mode boxes): a box, as the client sends it
            $reply = $this->act($name, str_ends_with($name, '_pick') ? ['pick' => random_int(0, 2)] : []);
            $steps[] = $reply;
            $actions[] = $name;
            if (count($steps) > 500) {
                throw new RuntimeException('Booongo round never ended (500 steps)');
            }
        }

        return ['start_credit' => $start, 'steps' => $steps, 'actions' => $actions];
    }

    /** Whether the server waits for the next paid spin. @param array<string, mixed> $reply */
    public static function idle(array $reply): bool
    {
        $c = (array) ($reply['context'] ?? []);

        return $c === [] || (($c['round_finished'] ?? true) && in_array('spin', (array) ($c['actions'] ?? ['spin']), true));
    }

    /** @param array<string, mixed> $reply */
    private function nextAction(array $reply): string
    {
        $actions = array_values(array_diff((array) ($reply['context']['actions'] ?? []), ['spin', 'buy_spin', 'bonus_buy']));
        if ($actions === []) {
            throw new RuntimeException('Booongo round in play offers no action: '.json_encode($reply['context']['actions'] ?? null));
        }

        return (string) $actions[0];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function act(string $name, array $params = []): array
    {
        return $this->last = $this->call('play', ['action' => ['name' => $name, 'params' => (object) $params],
            'quick_spin' => false, 'sound' => false, 'autogame' => false, 'mobile' => false, 'portrait' => false]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function call(string $command, array $extra = []): array
    {
        $body = ['command' => $command, 'request_id' => bin2hex(random_bytes(16))] + ($this->session !== null ? ['session_id' => $this->session] : []) + $extra;
        $r = $this->http()->withBody(json_encode($body) ?: '{}', 'application/json')->post($this->server.'?gsc='.$command);
        $j = $r->json();
        if (! is_array($j)) {
            throw new RuntimeException("Booongo {$command}: HTTP {$r->status()} ".mb_strimwidth($r->body(), 0, 300));
        }
        if (strtolower((string) ($j['status']['code'] ?? 'ok')) !== 'ok') {
            throw new RuntimeException("Booongo {$command}: ".json_encode($j['status']));
        }
        if (isset($j['session_id'])) {
            $this->session = (string) $j['session_id'];
        }
        if (isset($j['context'])) {
            $this->last = $j;
        }

        return $j;
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent('Mozilla/5.0')->withHeaders(['Origin' => 'https://static.joker-entry.link'])->timeout(30)->retry(2, 2000, throw: false);
    }
}
