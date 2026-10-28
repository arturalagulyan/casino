<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\PlayNGoRound;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\PlayNGo\PlayNGoLibrary;
use App\Services\GamePlay\SpinResult;
use Illuminate\Support\Facades\Log;

/**
 * Play'n GO's line protocol, server side — what its game client posts over
 * HTTP (we run it with `defaultProtocol: http`, so every packet is a plain
 * POST; Play'n GO's own servers also take it over a SignalR hub).
 *
 * A packet is `d=<seq>\r\n<session>\r\n<command lines>`; the reply is
 * `d=<reply lines>`. Commands are space-separated ints/strings, the first
 * naming the command: 103 open session, 101 login, 104 game init, 0 poll,
 * `1 <coins> <lines> <coin value> 1` spin, `2 4 0` / `2 6` round steps,
 * `4 0` collect.
 *
 * Spins are served from the game's outcome library ({@see PlayNGoLibrary}):
 * whole rounds recorded on Play'n GO's practice server. The stake is taken
 * and the round's win paid when the spin comes in; the round's recorded
 * replies are then served step by step, their cash fields rescaled from the
 * recorded coin value to the player's and the balance replaced by ours.
 */
class PlayNGoProtocol
{
    public function __construct(private readonly PlayNGoLibrary $library) {}

    public function dispatch(GameContext $context, string $body): string
    {
        $lines = preg_split('/\r?\n/', trim($body)) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => $l !== ''));
        array_shift($lines);   // d=<seq>
        array_shift($lines);   // session id — the route already names our session
        $out = [];
        foreach ($lines as $line) {
            $out = array_merge($out, $this->command($context, trim($line)));
        }

        return 'd='.implode("\r\n", $out)."\r\n\r\n";
    }

    /** @return list<string> reply lines */
    private function command(GameContext $context, string $line): array
    {
        $p = explode(' ', $line);
        $uid = $context->session()->user_id;
        $sid = substr($context->session()->token, 0, 11);

        return match ($p[0]) {
            '103' => ["103 \"{$sid}\""],
            '101' => ["103 \"{$sid}!{$uid}\"", "101 {$uid} \"{$this->currency($context)}\" \"\" \"ZZ\" \"user{$uid}\" \"\"", '127 "'.now()->utc()->format('Y-m-d\TH:i:s\Z').'"'],
            '104' => $this->init($context),
            '0' => [],
            '1' => $this->spin($context, $p),
            // the client starting a free round's board (its results came with the step before)
            '7' => ['83 0'],
            default => $this->step($context, $line),
        };
    }

    /** @return list<string> */
    private function init(GameContext $context): array
    {
        $context->statePut(['playngo' => null]);   // a round left unfinished was already settled
        $values = $this->coinValues($context);
        $rtp = $this->rtp($context);

        return [
            '104 1',
            '54 '.count($values).' '.implode(' ', $values).' 1',
            '57 "<custom><RTP Value=\"'.$rtp.'\" /></custom>"',
            "60 {$rtp} 0 0 1",
            '52 '.$this->cents($context->balance()).' 0 0',
            '83 0',
            '91 '.$context->session()->user_id.' "'.$context->session()->token.'"',
            '109',
        ];
    }

    /** @return list<string> */
    private function spin(GameContext $context, array $p): array
    {
        $coins = (int) ($p[1] ?? 0);
        $cv = (int) ($p[3] ?? 0);
        $cfg = $this->config($context);
        if ($coins !== (int) $cfg['bet_coins'] || ! in_array($cv, $this->coinValues($context), true)) {
            return ['111 "Invalid bet"'];
        }
        $stake = $coins * $cv / 100;
        if ($stake > $context->balance() + 1e-9) {
            return ['111 "There isn\'t enough funds on the account"'];
        }
        $context->placeBet($stake);
        $budget = min($context->bankAvailable(), $context->maxWin($stake));
        $round = $this->library->pick($cfg['gid'], $context->rtpTarget(), (int) floor($budget * 100 / $cv));
        $win = $round->win_coins * $cv / 100;
        $paid = $context->awardWin($win);
        $context->recordRound(new SpinResult($stake, $paid, [], [], 'bet', ['playngo_round' => $round->id]), json_encode(['round' => $round->id, 'cv' => $cv]) ?: '');

        $steps = $round->steps;
        $context->statePut(['playngo' => ['round' => $round->id, 'next' => 1, 'cv' => $cv,
            'balance' => $this->cents($context->balance())]]);

        // the spin's own reply shows the balance after the stake; the win lands at collect
        return $this->rescale($steps[0]['reply'], $cv, (int) $cfg['record_coin'], $this->cents($context->balance() - $paid));
    }

    /**
     * A round step. `2 4 <k>` acknowledges how far the client has shown the
     * cascade (k award steps) — Play'n GO just echoes it. `2 6` (on to the
     * next board) and `4 0` (collect) move the round on: the next recorded
     * step sent with that same command answers it.
     */
    private function step(GameContext $context, string $line): array
    {
        $state = $context->stateGet('playngo');
        if (str_starts_with($line, '2 4 ')) {
            return [$line];
        }
        if (! is_array($state) || ! in_array($line, ['2 6', '4 0'], true)) {
            return [];
        }
        $round = PlayNGoRound::query()->find($state['round']);
        $steps = $round !== null ? $round->steps : [];
        for ($i = (int) $state['next']; isset($steps[$i]) && $steps[$i]['sent'] !== $line; $i++);
        if (! isset($steps[$i])) {
            Log::info("Play'n GO round {$state['round']}: client sent '{$line}', the recording has no such step left");

            return [];
        }
        $state['next'] = $i + 1;
        $context->statePut(['playngo' => $line === '4 0' ? null : $state]);

        return $this->rescale($steps[$i]['reply'], (int) $state['cv'], (int) $this->config($context)['record_coin'], (int) $state['balance']);
    }

    /**
     * A recorded reply for this player: the coin value in board lines, cash
     * amounts (round totals `3`, collect `6`) scaled from the recorded coin
     * value, and the balance (`52`) ours.
     *
     * @return list<string>
     */
    public function rescale(string $reply, int $cv, int $recordCv, int $balanceCents): array
    {
        $reply = (string) preg_replace('/^d=/', '', $reply);
        $out = [];
        foreach (preg_split('/\r?\n/', $reply) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $line = match (true) {
                (bool) preg_match('/^1 (\d+) (\d+) \d+ /', $line) => (string) preg_replace('/^1 (\d+) (\d+) \d+ /', "1 $1 $2 {$cv} ", $line),
                (bool) preg_match('/^3 (\d+) (\d+) (\d+)( .*)?$/', $line, $m) => "3 {$m[1]} {$m[2]} ".intdiv((int) $m[3] * $cv, max(1, $recordCv)).($m[4] ?? ''),
                (bool) preg_match('/^6 (\d+)$/', $line, $m) => '6 '.intdiv((int) $m[1] * $cv, max(1, $recordCv)),
                (bool) preg_match('/^52 \d+ (.*)$/', $line, $m) => "52 {$balanceCents} {$m[1]}",
                default => $line,
            };
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Coin values (cents) the player may pick: the game's bet options as a
     * 10-coin stake each (bet 1.00 → coin value 10), whole cents only.
     *
     * @return list<int>
     */
    public function coinValues(GameContext $context): array
    {
        $coins = (int) $this->config($context)['bet_coins'];
        $out = [];
        foreach ($context->betOptions() as $bet) {
            $cv = (float) $bet * 100 / $coins;
            if ($cv >= 1 && abs($cv - round($cv)) < 1e-6) {
                $out[] = (int) round($cv);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out ?: [(int) $this->config($context)['record_coin']];
    }

    /** @return array{gid: string, bet_coins: int, record_coin: int} */
    private function config(GameContext $context): array
    {
        $c = (array) ($context->config()->bonusConfig()['playngo'] ?? []);

        return ['gid' => (string) ($c['gid'] ?? ''), 'bet_coins' => (int) ($c['bet_coins'] ?? 10), 'record_coin' => (int) ($c['record_coin'] ?? 10)];
    }

    /** The return the player is shown: our target, which the library can't exceed (Play'n GO's own return). */
    private function rtp(GameContext $context): int
    {
        $library = $this->library->stats($this->config($context)['gid'])['rtp'] ?: 96.0;

        return (int) round(min($context->rtpTarget(), $library));
    }

    private function currency(GameContext $context): string
    {
        return $context->currency->value;
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
