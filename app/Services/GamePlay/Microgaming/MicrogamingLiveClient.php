<?php

namespace App\Services\GamePlay\Microgaming;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A bare client for Microgaming's game server as Betboy runs it (the "Veyron"
 * API behind `https://<session>.joker-entry.link/skvCore/WebEngine.php?`), used
 * at import time to read a game's setup and record whole rounds for its
 * outcome library. Fun credits only (Betboy's demo: currency NAN).
 *
 * Wire:
 *   POST <base>/accounts/login/demo  {}                → {account, tokens: {userToken, …}}
 *   POST <base>/games/module/<mid>/client/<cid>/play   (Authorization: Bearer <userToken>)
 *        {packet: {packetType: 7, payload: "<Pkt version=…><Id mid cid sid verb/>…<Request …/></Pkt>",
 *                  useFilter: true, isBase64Encoded: false}, context: {}}
 *      → {context: {…}, packet: {payload: "<Pkt><Id … verb=…/><Response>…</Response></Pkt>"}}
 *
 * Money in the packets is in cents (`Player balance`, `Slot win`, …).
 *
 * What a game sends depends on its client family ({@see MicrogamingFamily}):
 * TheForce slots `verb="AdvSlot"` with `<Request verbex='Refresh|Spin|FreeSpin|BonusPick' …/>`.
 */
class MicrogamingLiveClient
{
    private string $token = '';

    /** the Refresh reply */
    public string $init = '';

    /** the last reply */
    public string $last = '';

    /** what the next BonusPick picks (given the reply asking for it), else the first item */
    public ?\Closure $chooser = null;

    /** Choices a feature offers without listing them (King Tusk: 3) — the template's `feature_choices`. */
    public int $featureChoices = 0;

    /**
     * @param  array<string, mixed>  $info  the launch page's startup info (moduleId, clientId, productId, gameUrl, engine, …)
     */
    public function __construct(public readonly string $base, public readonly array $info, public int|string $packetVersion, public readonly bool $quantum = false) {}

    /** the demo login's account */
    public array $account = [];

    /** From Betboy's launch URL (the Titan page itself). */
    public static function fromLaunch(string $url): self
    {
        return self::fromHtml(Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 2000)->get($url)->body(), $url);
    }

    public static function fromHtml(string $html, string $url): self
    {
        $info = self::startupInfo($html);
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new RuntimeException("Not a launch URL: {$url}");
        }

        // Mahi Gaming's "Zen" clients speak Quantum: JSON in the packet (version 6.1)
        $quantum = ($info['archon']['engine'] ?? $info['engine'] ?? '') === 'vshimzen';

        return new self("https://{$host}/skvCore/WebEngine.php?", $info, $quantum ? '6.1' : (MicrogamingFamily::packetVersion($html, $info) ?? 0), $quantum);
    }

    /**
     * The launch page's startup info: Titan pages' `var __<hex>_tsi__ = {…};`,
     * or the classic (CoreAssets) pages' `mgsCasinoParams` + `GAMESTATIC`.
     *
     * @return array<string, mixed> moduleId, clientId, productId, gameUrl, engine, gameVersion, …
     */
    public static function startupInfo(string $html): array
    {
        if (preg_match('/var __\w+_tsi__ = (\{.*?\});\s*<\/script>/s', $html, $m) && is_array($info = json_decode($m[1], true))
            && isset($info['moduleId'], $info['clientId'], $info['productId'], $info['gameUrl'])) {
            return $info;
        }
        $p = preg_match('/var mgsCasinoParams = (\{.*?\});\s*\n/s', $html, $m) ? json_decode($m[1], true) : null;
        // the game's static folder: GAMESTATIC, else (Gods of Giza's mobile client) commonStatic's sibling named gameName
        $gameUrl = null;
        if (preg_match('/var GAMESTATIC = "([^"]+)"/', $html, $g)) {
            $gameUrl = $g[1];
        } elseif (isset($p['gameName']) && preg_match('#<base href="(https?://[^"]+/)commonStatic/"#', $html, $b)) {
            $gameUrl = $b[1].$p['gameName'].'/';
        }
        if (is_array($p) && isset($p['moduleID'], $p['clientID']) && $gameUrl !== null) {
            return ['moduleId' => (int) $p['moduleID'], 'clientId' => (int) $p['clientID'], 'productId' => (int) ($p['productId'] ?? $p['casinoID'] ?? 0),
                'clientTypeId' => (int) ($p['clientTypeID'] ?? 1), 'gameUrl' => $gameUrl, 'game' => $p['gameName'] ?? null,
                'engine' => 'coreassets', 'gameVersion' => preg_match('/CoreAssets[.\w]*?\.(\d+_\d+_\d+_\d+)\.js/', $html, $v) ? "CoreAssets {$v[1]}" : 'CoreAssets'];
        }
        throw new RuntimeException('Not a Microgaming launch page (no startup info)');
    }

    /** @return string the Refresh reply (a round left in play by an earlier session is played out first) */
    public function open(): string
    {
        $login = $this->post('/accounts/login/demo', []);
        $this->token = (string) ($login['tokens']['userToken'] ?? '');
        $this->account = (array) ($login['account'] ?? []);
        $currency = (string) ($login['account']['core']['currencyIsoCode'] ?? '');
        if ($this->token === '' || $currency !== 'NAN') {
            throw new RuntimeException("Microgaming login is not a demo session (currency '{$currency}') — refusing to play it");
        }
        // the packet version the client stamps on <Pkt>: the game config's, else the first the server answers
        foreach ($this->packetVersion ? [$this->packetVersion] : [5, 4, 6, 3, 7, 2, 1, 8] as $version) {
            $this->packetVersion = $version;
            try {
                $this->init = $this->send(MicrogamingFamily::refresh($this->quantum));
                break;
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'no reply') || $version === 8) {
                    throw $e;
                }
            }
        }
        $guard = 0;
        while (($next = MicrogamingFamily::next($this->last, $this->chooser, $this->featureChoices)) !== null && $guard++ < 500) {
            $this->send($next);
        }

        return $this->init;
    }

    /** Balance, cents. */
    public function credit(): int
    {
        if (($q = self::quantum($this->last)) !== null) {
            return (int) ($q['User']['Balance'] ?? 0);
        }

        return (int) (self::attr($this->last, 'Player', 'balance') ?? 0);
    }

    /**
     * One whole round: the paid spin, then whatever the reply asks for
     * (a bonus pick, free spins, …) until the game waits for the next paid spin.
     *
     * @param  array<string, string>  $spin  the Spin request's attributes
     * @return array{start_credit: int, steps: list<array{q: string, p: array<string, string>, r: string}>}
     */
    public function round(array $spin): array
    {
        $start = $this->credit();
        $request = ['Spin', $spin];
        $steps = [];
        do {
            $reply = $this->send($request);
            $steps[] = ['q' => $request[0], 'p' => $request[1], 'r' => $reply];
            if (count($steps) > 600) {
                throw new RuntimeException('Microgaming round never ended (600 steps)');
            }
        } while (($request = MicrogamingFamily::next($reply, $this->chooser, $this->featureChoices)) !== null);
        // the game still waits for something we don't know how to send: fail loudly, never record half a round
        // (Framework state 3: a feature just finished — the next paid spin goes on from there)
        if (($state = self::attr($reply, 'Framework', 'state')) !== null && $state !== '0' && $state !== '3') {
            throw new RuntimeException("Microgaming round left pending (Framework state {$state}) after "
                .implode(',', array_column($steps, 'q')).': '.mb_strimwidth((string) preg_replace('/<Achievements>.*$/s', '', $reply), 0, 600));
        }

        return ['start_credit' => $start, 'steps' => $steps];
    }

    /**
     * Send one request (`[verbex, attributes]`) and return the reply payload.
     *
     * @param  array{0: string, 1: array<string, string>}  $request
     */
    public function send(array $request): string
    {
        [$verbex, $attrs] = $request;
        $mid = (int) $this->info['moduleId'];
        $cid = (int) $this->info['clientId'];
        $payload = sprintf('<Pkt version="%s"><Id mid="%d" cid="%d" sid="%d" verb="AdvSlot" lang="en"/>%s</Pkt>',
            $this->packetVersion, $mid, $cid, (int) $this->info['productId'], self::request($verbex, $attrs));
        $reply = $this->post("/games/module/{$mid}/client/{$cid}/play",
            ['packet' => ['packetType' => 7, 'payload' => $payload, 'useFilter' => true, 'isBase64Encoded' => false], 'context' => (object) []]);
        $xml = (string) ($reply['packet']['payload'] ?? '');
        if ($xml === '' || ! str_contains($xml, '<Response')) {
            throw new RuntimeException("Microgaming {$verbex}: no reply".($xml !== '' ? ': '.mb_strimwidth($xml, 0, 300) : ''));
        }
        if (preg_match('/<Error\b[^>]*>/', $xml, $e)) {
            throw new RuntimeException("Microgaming {$verbex}: {$e[0]}");
        }

        return $this->last = $xml;
    }

    /**
     * `<Request verbex='…' a='…'/>`, or — a Quantum request (`['json' => …]`) —
     * `<Request verbex='…'><Pkt><![CDATA[{…}]]></Pkt></Request>`.
     *
     * @param  array<string, string>  $attrs
     */
    public static function request(string $verbex, array $attrs): string
    {
        if (isset($attrs['json'])) {
            return "<Request verbex='{$verbex}'><Pkt><![CDATA[{$attrs['json']}]]></Pkt></Request>";
        }
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' '.$k."='".htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1)."'";
        }

        return "<Request verbex='{$verbex}'{$a}/>";
    }

    /** @return array<string, mixed>|null a Quantum reply's JSON (`<Q version="…">{…}</Q>`) */
    public static function quantum(string $xml): ?array
    {
        return preg_match('/<Q\b[^>]*>(.*)<\/Q>/s', $xml, $m) && is_array($j = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1), true)) ? $j : null;
    }

    /** An attribute of the first `<$element …>` in a packet. */
    public static function attr(string $xml, string $element, string $name): ?string
    {
        if (! preg_match('/<'.preg_quote($element, '/').'\b[^>]*>/', $xml, $m)) {
            return null;
        }

        return preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $m[0], $v) ? html_entity_decode($v[1], ENT_QUOTES | ENT_XML1) : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $request = Http::withUserAgent('Mozilla/5.0')->timeout(30)->retry(2, 1000, throw: false)
            ->withHeaders(['X-ClientTypeId' => (string) ($this->info['clientTypeId'] ?? 1)] + ($this->token !== '' ? ['Authorization' => "Bearer {$this->token}"] : []));
        $response = $request->asJson()->post($this->base.$path, $body);
        if (! $response->successful()) {
            throw new RuntimeException("Microgaming {$path}: HTTP {$response->status()}");
        }

        return (array) ($response->json() ?? []);
    }
}
