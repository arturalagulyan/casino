<?php

namespace App\Services\GamePlay\NetEnt;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Betboy lobby, as a logged-in player sees it: its game list and its
 * game launcher (JSON-RPC at /rpc). Logs in with an "AuthByToken" link's
 * token (BETBOY_TOKEN / --betboy-token), which sets a `token` cookie.
 *
 * Only ever launches DEMO sessions (`isDemo: true` — fun credits, currency
 * NAN): recording an outcome library must never stake the account's money.
 */
class BetboySite
{
    private const string BASE = 'https://betboy.fun';

    private ?CookieJar $jar = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $games = null;

    public function __construct(private ?string $token = null) {}

    public function withToken(?string $token): static
    {
        if ($token) {
            $this->token = $token;
            $this->jar = null;
        }

        return $this;
    }

    /** A game of the lobby by its id (happy_riches), name (Happy Riches) or numeric id. @return array<string, mixed> */
    public function game(string $key): array
    {
        $norm = fn (string $s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        // the exact id first: a name can be shared — NetEnt's `football` is
        // "Football", and so is RedRake's `bingo_football`, listed earlier
        $tests = [
            fn (array $g) => (string) $g['stringId'] === $key,
            fn (array $g) => (string) $g['id'] === $key,
            fn (array $g) => $norm((string) $g['stringId']) === $norm($key),
            fn (array $g) => $norm((string) $g['name']) === $norm($key),
        ];
        foreach ($tests as $test) {
            foreach ($this->games() as $g) {
                if ($test($g)) {
                    return $g;
                }
            }
        }
        throw new RuntimeException("Betboy's lobby has no game '{$key}'");
    }

    /** @return list<array<string, mixed>> */
    public function games(): array
    {
        // an empty lobby is a hiccup (e.g. a parallel login), not "no games" — ask again once
        for ($try = 0; $this->games === null || ($this->games === [] && $try < 2); $try++) {
            if ($try > 0) {
                sleep(3);
            }
            $this->games = (array) ($this->rpc('Game.getList')['data'] ?? []);
        }

        return $this->games;
    }

    /**
     * A fresh DEMO session of a game. Returns the launch URL (one host per
     * session, e.g. https://<session>.joker-entry.link/) and the page it
     * redirects to (IGT: frames) — the provider's real client URL with its parameters.
     *
     * @return array{session: string, launch: string, page: string}
     */
    public function demo(string $stringId, string $device = 'desktop'): array
    {
        $run = $this->run($stringId, $device);
        $launch = (string) $run['url'];
        $response = Http::withUserAgent('Mozilla/5.0')->withOptions(['allow_redirects' => false])->timeout(30)->get($launch);
        $page = $response->header('Location');
        // IGT: no redirect — a wrapper page framing the client
        if ($page === '' && preg_match('#<iframe\b[^>]*\bsrc="([^"]+)"#i', $response->body(), $m)) {
            $page = html_entity_decode($m[1]);
        }
        // Greentube (Novoline): the launch page is the client itself
        // Booongo: the launch page is the game runner's own page
        // EGT: the launch page is the client's own page (initDesktopHtml(connectionParams))
        // Pragmatic: the launch page is the client's own page (Html5GameManager.init({gameConfig…}))
        // Wazdan: the launch page is the client's own page (var SID2 = [char codes of its parameters])
        if ($page === '' && (str_contains($response->body(), 'jsout.js') || str_contains($response->body(), 'window._PROVIDER')
            || str_contains($response->body(), 'initDesktopHtml(') || str_contains($response->body(), 'Html5GameManager.init(') || str_contains($response->body(), 'var SID2 = ['))) {
            $page = $launch;
        }
        if ($page === '') {
            throw new RuntimeException("Betboy's launch {$launch} didn't redirect to a game page");
        }

        return ['session' => (string) ($run['stringId'] ?? ''), 'launch' => $launch, 'page' => $page];
    }

    /**
     * A DEMO launch's URL, as is — for providers whose launch page is the
     * client itself, read by their own live client (Microgaming's Titan page).
     */
    public function launchUrl(string $stringId, string $device = 'desktop'): string
    {
        return (string) $this->run($stringId, $device)['url'];
    }

    /** @return array<string, mixed> Game.run's result for a DEMO launch (with a non-empty url) */
    private function run(string $stringId, string $device): array
    {
        // Betboy limits how often games are launched (error 6038, "Too many game
        // launches"); sampling drops a session per unplayable bonus — wait it out
        for ($wait = 60; ; $wait = min($wait * 2, 600)) {
            try {
                $run = $this->rpc('Game.run', ['gameStringId' => $stringId, 'place' => 'game_list', 'lang' => 'en',
                    'deviceType' => $device, 'theme' => 'dark', 'isDemo' => true]);
                break;
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), '"code":6038') || $wait >= 600) {
                    throw $e;
                }
                sleep($wait);
            }
        }
        if ((string) ($run['url'] ?? '') === '') {
            throw new RuntimeException("Betboy didn't launch '{$stringId}': ".json_encode($run));
        }

        return $run;
    }

    public function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : self::BASE.'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function rpc(string $method, ?array $params = null): array
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params !== null ? ['params' => $params] : []);
        $response = Http::withUserAgent('Mozilla/5.0')->acceptJson()->withOptions(['cookies' => $this->jar()])
            ->timeout(60)->post(self::BASE.'/rpc', $body);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException("Betboy {$method}: HTTP {$response->status()}");
        }
        if ($error = $response->json('error')) {
            throw new RuntimeException("Betboy {$method}: ".json_encode($error));
        }

        return (array) $response->json('result');
    }

    private function jar(): CookieJar
    {
        if ($this->jar !== null) {
            return $this->jar;
        }
        $token = $this->token ?: (string) config('services.betboy.token');
        if ($token === '') {
            throw new RuntimeException('No Betboy login — pass --betboy-token=<token from an AuthByToken link> or set BETBOY_TOKEN');
        }
        $this->jar = new CookieJar;
        Http::withUserAgent('Mozilla/5.0')->withOptions(['cookies' => $this->jar, 'allow_redirects' => false])
            ->timeout(30)->get(self::BASE.'/AuthByToken', ['token' => $token]);
        if ($this->jar->getCookieByName('token') === null) {
            throw new RuntimeException('Betboy refused the login token (no session cookie) — get a fresh AuthByToken link');
        }

        return $this->jar;
    }
}
