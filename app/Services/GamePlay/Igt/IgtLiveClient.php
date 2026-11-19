<?php

namespace App\Services\GamePlay\Igt;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for IGT's RGS demo server (Betboy's `…/skvCore/WebEngine.php`),
 * used at import time to read a game's paytable / init and record whole
 * rounds for its outcome library. Fun credits only (currency FPY, "fun play").
 *
 * Wire: `{server}/<action>&<params>` — the server URL ends in `?`, so the
 * action is the query string's first part:
 *
 *   POST …?/authenticate         JSON login form → {"Authentication":{"Status":"Success"}}
 *   GET  …?/paytable&…           PaytableResponse (lines, reels, bet slider)
 *   GET  …?/currency&…           money format
 *   GET  …?/initstate&…          GameLogicResponse: balance, default bet
 *   POST …?/play&…               {"GameLogicRequest":{"ActionInput":{"Action":"play"},
 *                                  "PatternSliderInput":{"BetPerPattern":1,"PatternsBet":40}, …}}
 *
 * The server wants paytable and currency read before initstate. Amounts in
 * replies are credits of the page's denomination (`denom`: 0.01 for most
 * games, 1 for Cleopatra, whose line bets are money); `Balance` is money.
 */
class IgtLiveClient
{
    public const string SOFTWARE_ID = '200-1196-001';   // what Betboy's page sends for every game; the session names the game

    private int $tx = 0;

    /** @var array<string, mixed> */
    public array $paytable = [];

    /** @var array<string, mixed> */
    public array $init = [];

    public array $currency = [];

    private float $balance = 0.0;

    /**
     * Pick names the game's pickers offer (`L0C1R2`, `4`, …), learnt from the
     * PickerOutcome replies list. Most pickers take a plain `play` and pick
     * for us; Golden Goddess's rose needs a real pick (else its free spins
     * answer getSymbolError) — its names are only known once seen.
     *
     * @var list<string>
     */
    public array $pickNames = [];

    public function __construct(private readonly string $server, public readonly float $denom = 0.01) {}

    /** From the client page (`…/iframeDesktop.html?…&server=<WebEngine.php?>`). */
    public static function fromPage(string $page): self
    {
        parse_str((string) parse_url($page, PHP_URL_QUERY), $q);
        $server = (string) ($q['server'] ?? '');
        if (! str_contains($server, 'WebEngine.php')) {
            throw new RuntimeException("Not an IGT client page (no WebEngine server): {$page}");
        }

        return new self($server, (float) ($q['denom'] ?? 0.01) ?: 0.01);
    }

    /** @return array<string, mixed> the init reply's GameLogicResponse */
    public function open(): array
    {
        $auth = $this->http()->withBody(json_encode(self::params($this->denom) + ['deviceInfoString' => '', 'sessiontoken' => '']) ?: '', 'application/json')
            ->post($this->url('authenticate', false));
        if (($auth->json('Authentication.Status') ?? '') !== 'Success') {
            throw new RuntimeException('IGT refused the login: '.mb_strimwidth($auth->body(), 0, 300));
        }
        $this->paytable = (array) $this->http()->get($this->url('paytable'))->json('PaytableResponse');
        $this->currency = (array) $this->http()->get($this->server.'/currency&currencycode=FPY&language=en&skincode=GL02&nscode=GLGB')->json();
        $this->init = (array) $this->http()->get($this->url('initstate'))->json('GameLogicResponse');
        if ($this->paytable === [] || ! isset($this->init['OutcomeDetail'])) {
            throw new RuntimeException('IGT sent no paytable / init');
        }
        if (($this->init['OutcomeDetail']['NextStage'] ?? 'BaseGame') !== 'BaseGame') {
            throw new RuntimeException('IGT session opened inside a feature ('.$this->init['OutcomeDetail']['NextStage'].')');
        }
        $this->tx = (int) preg_replace('/\D+/', '', explode('-', (string) ($this->init['OutcomeDetail']['TransactionId'] ?? 'R0-0'))[1] ?? '0');
        $this->balance = (float) ($this->init['OutcomeDetail']['Balance'] ?? 0);

        return $this->init;
    }

    public function balance(): float
    {
        return $this->balance;
    }

    /** Lines (patterns) a full bet covers: the slider's maximum. */
    public function patterns(): int
    {
        return (int) ($this->paytable['PatternSliderInfo']['PatternInfo']['@max'] ?? $this->init['PatternSliderInput']['PatternsBet'] ?? 1);
    }

    /** The smallest line bet the slider offers — what rounds are recorded at. */
    public function minStep(): float
    {
        $steps = array_map('floatval', (array) ($this->paytable['PatternSliderInfo']['BetInfo']['Step'] ?? [1]));

        return $steps === [] ? 1.0 : min($steps);
    }

    /**
     * One whole round at `$perPattern` (credits) a line on every line: the
     * paid `play`, then — as the client does — `play` again while the round is
     * still in a feature.
     *
     * @return array{start_credit: int, steps: list<array<string, mixed>>, bet: int, win: int} all cents
     */
    public function round(float $perPattern = 1): array
    {
        $start = (int) round($this->balance * 100);
        $steps = [];
        $reply = null;
        do {
            $pending = $reply !== null && (int) ($reply['PickerSummaryOutcome']['PicksRemaining']['#text'] ?? $reply['PickerSummaryOutcome']['PicksRemaining'] ?? 0) > 0;
            $reply = $this->play($perPattern, 'play', $pending && $this->pickNames !== [] ? $this->pickNames[array_rand($this->pickNames)] : null);
            $this->learnPicks($reply);
            $steps[] = $reply;
            if (count($steps) > 500) {
                throw new RuntimeException('IGT round never ended (500 steps)');
            }
        } while (self::continues($reply));
        $first = $steps[0]['OutcomeDetail'];
        $last = end($steps)['OutcomeDetail'];

        $credits = (float) $last['Settled'] ?: (float) $first['Pending'] ?: (float) $first['Settled'];

        return ['start_credit' => $start, 'steps' => $steps,
            'bet' => (int) round($credits * $this->denom * 100), 'win' => (int) round((float) $last['Payout'] * $this->denom * 100)];
    }

    /** @return array<string, mixed> */
    public function play(float $perPattern = 1, string $action = 'play', ?string $pick = null): array
    {
        $body = ['GameLogicRequest' => ['ActionInput' => ['Action' => $action],
            'PatternSliderInput' => ['BetPerPattern' => floor($perPattern) == $perPattern ? (int) $perPattern : $perPattern, 'PatternsBet' => $this->patterns()],
            'TransactionId' => 'R2032-'.$this->tx]];
        if ($pick !== null) {
            $body['GameLogicRequest']['PickerInput'] = ['Pick' => ['@name' => $pick, '#text' => $pick]];
        }
        $r = $this->http()->withBody(json_encode($body) ?: '', 'application/json')->post($this->url('play'));
        $g = $r->json('GameLogicResponse');
        if (! is_array($g) || ! isset($g['OutcomeDetail'])) {
            throw new RuntimeException("IGT {$action}: HTTP {$r->status()} ".mb_strimwidth($r->body(), 0, 300));
        }
        $this->tx = (int) (explode('-', (string) $g['OutcomeDetail']['TransactionId'])[1] ?? $this->tx + 1);
        $this->balance = (float) $g['OutcomeDetail']['Balance'];

        return $g;
    }

    /** @param array<string, mixed> $reply */
    private function learnPicks(array $reply): void
    {
        $layers = (array) ($reply['PickerOutcome']['Layer'] ?? []);
        foreach (isset($layers['Pick']) ? [$layers] : $layers as $layer) {
            $picks = (array) ($layer['Pick'] ?? []);
            foreach (isset($picks['@name']) ? [$picks] : $picks as $p) {
                if (isset($p['@name']) && ! in_array((string) $p['@name'], $this->pickNames, true)) {
                    $this->pickNames[] = (string) $p['@name'];
                }
            }
        }
    }

    /** Whether the client plays on after this reply: the round is still in a feature. */
    public static function continues(array $reply): bool
    {
        $o = (array) ($reply['OutcomeDetail'] ?? []);

        return ($o['NextStage'] ?? 'BaseGame') !== 'BaseGame' || ($o['GameStatus'] ?? '') === 'InProgress';
    }

    /** @return array<string, string> the RGS parameters Betboy's page sends */
    public static function params(float $denom = 0.01): array
    {
        return ['skincode' => 'GL02', 'minbet' => '0.01', 'countrycode' => 'SI', 'channel' => 'INT', 'language' => 'en', 'nscode' => 'GLGB',
            'technology' => 'HTML', 'currencycode' => 'FPY', 'securetoken' => '', 'playMode' => 'real', 'softwareid' => self::SOFTWARE_ID,
            'denomamount' => (string) $denom, 'presenttype' => 'STD', 'uniqueid' => ''];
    }

    private function url(string $action, bool $query = true): string
    {
        return $this->server.'/'.$action.($query ? '&'.http_build_query(self::params($this->denom)) : '');
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent('Mozilla/5.0')->withHeaders(['Origin' => 'https://static.joker-entry.link'])->timeout(30);
    }
}
