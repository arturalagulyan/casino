<?php

namespace App\Services\GamePlay\Amatic;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads an Amatic operator's casino lobby (GrandX-style TYPO3 site, e.g.
 * https://www.grandx.com/casino/) into its game list:
 *
 *  - categories are `<section id="gamecatN">` blocks, named by the nav links
 *    (`<a href="casino/#gamecatN">Fruit Slots</a>`);
 *  - each tile is `<a class="wrap-game" title="…" href="nc/game/?game=…">`;
 *  - a tile's page holds the "Play for fun" Amatic launcher URL
 *    (`jQuery("#gameframe").attr('src', "https://…/gmsl/amanet/game.html?game=<key>&…")`),
 *    served only to a visitor coming from the lobby (session cookie + Referer).
 *
 * @phpstan-type LobbyGame array{title: string, param: string, categories: list<string>, url: ?string, key: ?string, poster: ?string}
 */
class AmaticLobbyScraper
{
    /** @return list<LobbyGame> */
    public function games(string $lobbyUrl, ?callable $progress = null): array
    {
        $jar = new CookieJar;
        $http = Http::withUserAgent('Mozilla/5.0')->timeout(60)->withOptions(['cookies' => $jar]);

        $lobby = $http->get($lobbyUrl);
        if (! $lobby->successful()) {
            throw new RuntimeException("Lobby {$lobbyUrl} answered {$lobby->status()}");
        }
        $html = $lobby->body();
        $base = preg_match('/<base\s+href="([^"]+)"/i', $html, $b) ? $b[1] : $this->origin($lobbyUrl).'/';

        $names = [];
        preg_match_all('/<a[^>]+href="[^"]*#(gamecat\d+)"[^>]*>\s*([^<]+?)\s*</', $html, $nav, PREG_SET_ORDER);
        foreach ($nav as $n) {
            $names[$n[1]] ??= html_entity_decode($n[2]);
        }

        preg_match_all('/<section[^>]*\bid="(gamecat\d+)"/', $html, $sec, PREG_OFFSET_CAPTURE);
        $cuts = array_map(fn ($x) => [$x[0], $x[1]], $sec[1]);
        $cuts[] = ['', strlen($html)];

        $games = [];
        for ($i = 0; $i < count($cuts) - 1; $i++) {
            $chunk = substr($html, $cuts[$i][1], $cuts[$i + 1][1] - $cuts[$i][1]);
            // the tile image (`data-src`) sits right before its link
            preg_match_all('/(?:data-src="([^"]*)"\s*>\s*)?<a\s+class="wrap-game"\s+title="([^"]*)"\s+href="([^"]*\?game=([^"&]+)[^"]*)"/', $chunk, $tiles, PREG_SET_ORDER);
            foreach ($tiles as $t) {
                $param = urldecode(html_entity_decode($t[4]));
                $games[$param] ??= ['title' => html_entity_decode($t[2]), 'param' => $param, 'page' => html_entity_decode($t[3]), 'categories' => [], 'poster' => null];
                $games[$param]['poster'] ??= $t[1] !== '' ? html_entity_decode($t[1]) : null;
                $category = $names[$cuts[$i][0]] ?? $cuts[$i][0];
                if (! in_array($category, $games[$param]['categories'], true)) {
                    $games[$param]['categories'][] = $category;
                }
            }
        }

        $out = [];
        foreach ($games as $g) {
            $url = null;
            // the site stops handing out launcher URLs after ~100 pages per
            // session — on a miss, start a fresh lobby session and retry
            for ($try = 0; $try < 3 && $url === null; $try++) {
                if ($try > 0) {
                    $jar = new CookieJar;
                    $http = Http::withUserAgent('Mozilla/5.0')->timeout(60)->withOptions(['cookies' => $jar]);
                    $http->get($lobbyUrl);
                }
                $page = $http->withHeaders(['Referer' => $lobbyUrl])->get($this->absolute($base, $g['page']));
                $url = $page->successful() && preg_match('/attr\(\s*[\'"]src[\'"]\s*,\s*"(https?:\/\/[^"]+\/amanet\/game\.html\?[^"]+)"/', $page->body(), $m)
                    ? html_entity_decode($m[1])
                    : null;
            }
            $key = $url && preg_match('/[?&]game=([^&]+)/', $url, $k) ? strtolower($k[1]) : null;
            $out[] = ['title' => $g['title'], 'param' => $g['param'], 'categories' => $g['categories'], 'url' => $url, 'key' => $key, 'poster' => $g['poster']];
            if ($progress) {
                $progress($g['title'].' → '.($key ?? 'no launcher URL'));
            }
        }

        return $out;
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
    }

    private function absolute(string $base, string $ref): string
    {
        return preg_match('#^https?://#', $ref) ? $ref : rtrim($base, '/').'/'.ltrim($ref, '/');
    }
}
