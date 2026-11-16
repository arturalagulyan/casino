<?php

namespace App\Services\GamePlay\NetEnt;

use App\Models\NetEntRound;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Plays a NetEnt game on a live demo session, the way its HTML5 client does:
 * `GET {server}servlet/CasinoGameServlet;jsession=<sid>?action=…&sessid=<sid>&gameId=…`
 * with flat `key=value&…` replies.
 *
 * A round is the spin plus every action its replies ask for next
 * (`nextaction=freespin`, … until `nextaction=spin`). A step the server
 * rejects throws {@see IncompleteRound}.
 */
class NetEntLiveClient
{
    private string $server;

    private string $session;

    private string $gameId;

    private int $credit = 0;

    /** @param string $page the client page URL a launch redirects to (carries server=, sessId=, gameId=) */
    public function __construct(string $page)
    {
        parse_str((string) parse_url($page, PHP_URL_QUERY), $q);
        $this->server = rtrim((string) ($q['server'] ?? ''), '/').'/';
        $this->session = (string) ($q['sessId'] ?? '');
        $this->gameId = (string) ($q['gameId'] ?? '');
        if ($this->server === '/' || $this->session === '' || $this->gameId === '') {
            throw new RuntimeException("Not a NetEnt client URL (needs server=, sessId=, gameId=): {$page}");
        }
    }

    public function gameId(): string
    {
        return $this->gameId;
    }

    /** The current (recorded) balance, cents. */
    public function credit(): int
    {
        return $this->credit;
    }

    /** @return array<string, string> */
    public function init(): array
    {
        return $this->fields($this->call('init', ['wantsfreerounds' => 'true', 'freeroundmode' => 'false', 'wantsreels' => 'true']));
    }

    public function raw(string $action, array $extra = []): string
    {
        return $this->call($action, $extra + ['wantsreels' => 'true', 'wantsfreerounds' => 'true', 'freeroundmode' => 'false']);
    }

    /**
     * The `bet.betlines` a spin sends to play everything an init offers:
     *  - a list of line ids `0,1,…,n-1` as the range `0-(n-1)`: some servers
     *    (Secrets of Christmas) read a comma list as one line; any other list as is
     *  - a value that is one of the `bl.i*.id` bet options — ways games' `0`,
     *    Ghost Pirates' choice of 3/9/27/81/`243` ways — as is
     *  - a line count (`30`) as the range `0-29`
     *
     * @param  array<string, string>  $init
     */
    public static function betLines(array $init): string
    {
        $raw = trim((string) ($init['bet.betlines'] ?? ''));
        if (str_contains($raw, ',')) {
            $ids = explode(',', $raw);

            return $ids === array_map('strval', range(0, count($ids) - 1)) ? '0-'.(count($ids) - 1) : $raw;
        }
        $ids = array_values(array_intersect_key($init, array_flip(preg_grep('/^bl\.i\d+\.id$/', array_keys($init)))));
        if ($raw !== '' && in_array($raw, $ids, true)) {
            return $raw;
        }

        return ctype_digit($raw) && (int) $raw > 0 ? '0-'.((int) $raw - 1) : $raw;
    }

    /**
     * One whole round at bet level `$level`, coin value `$denom`, with the
     * game's own spin parameters ({@see NetEntQuirks}).
     *
     * `stake` is what the round cost, in cents: the start credit less the
     * final one, plus the round's total win — the final reply settles the
     * round, whatever the game (lines, ways, cascades, free spins). Null when
     * that reply names no credit (some games' feature endings).
     *
     * @return array{start_credit: int, stake: ?int, steps: list<array{sent: string, reply: string}>}
     */
    public function round(string $betLines, int $level = 1, int $denom = 1): array
    {
        $start = $this->credit;
        $steps = [];
        $action = $startAction = NetEntQuirks::startAction($this->gameId);
        $bet = ['bet.betlevel' => $level, 'bet.denomination' => $denom, 'bet.betlines' => NetEntQuirks::betLines($this->gameId) ?? $betLines]
            + NetEntQuirks::spinParams($this->gameId);
        for ($i = 0; $i < 500; $i++) {
            $reply = $this->raw($action, $i === 0 ? $bet : []);
            $f = $this->fields($reply);
            if (($f['clientaction'] ?? '') !== $action) {
                throw new RuntimeException("NetEnt {$action}: unexpected reply ".Str::limit($reply, 200));
            }
            if (! isset($f['nextaction'])) {
                throw new IncompleteRound("NetEnt {$action}: rejected (".Str::limit($reply, 80).') after '.count($steps).' step(s)');
            }
            $steps[] = ['sent' => $action, 'reply' => $reply];
            // `nextaction` decides, not `gameover`: Hook's Heroes answers a spin
            // with gameover=true and nextaction=respin, the respins still to play
            $next = (string) $f['nextaction'];
            if ($next === $startAction) {   // the next round may start
                $stake = isset($f['credit']) ? $start - (int) $f['credit'] + (int) ($f['totalwin.cents'] ?? 0) : null;

                return ['start_credit' => $start, 'stake' => $stake, 'steps' => $steps];
            }
            $action = $next;
        }
        throw new RuntimeException('NetEnt round never ended (500 steps)');
    }

    private function call(string $action, array $extra): string
    {
        $query = http_build_query(['action' => $action, 'sessid' => $this->session, 'gameId' => $this->gameId] + $extra + ['no-cache' => (string) Str::uuid()]);
        $response = Http::withUserAgent('Mozilla/5.0')->timeout(30)
            ->get("{$this->server}servlet/CasinoGameServlet;jsession={$this->session}?{$query}");
        $body = (string) $response->body();
        // table games' replies carry no clientaction (`&credit=…&winnumber=…`)
        if (! $response->successful() || (! str_contains($body, 'clientaction=') && ! str_contains($body, 'credit='))) {
            throw new RuntimeException("NetEnt {$action}: HTTP {$response->status()} ".Str::limit($body, 200));
        }
        if (preg_match('/(?:^|&)credit=(\d+)/', $body, $m)) {
            $this->credit = (int) $m[1];
        }

        return $body;
    }

    /** @return array<string, string> */
    private function fields(string $reply): array
    {
        return NetEntRound::fields($reply);
    }
}
