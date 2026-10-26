<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * Amatic Keno over the shared Amatic socket protocol (no legacy backend
 * exists for it — wire format read from the client and Amatic's own server):
 *
 *   A/u25                       init — Amatic's captured init frame with our balance
 *   A/u271,<bet>,<p1:…:p10>     play — bet = index into the init's bet ladder (cents), up to 10 picks (0 = none)
 *   A/u272, A/u274              play again with the last bet and picks
 *   A/u270, A/u250, A/u350      last draw / resync / balance poll
 *
 * Reply `1<state>0<round><balance><win><bet><maxBet><maxWin><x>` + 10 picks
 * + 22 numbers (the 20 drawn, 2 unused). 20 of 80 are drawn at random —
 * re-drawn only when the shop's win pool can't cover the payout — and pay the
 * client's own table (picks × hits → × stake).
 */
class AmaticKenoHandler
{
    public function __construct(private readonly AmaticFormatter $fmt) {}

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    public function dispatch(GameContext $ctx, array $request): array
    {
        $raw = (string) ($request['gameData'] ?? '');
        $parts = explode(',', $raw);
        $state = (array) (((array) $ctx->stateGet('features', []))['keno'] ?? []);

        return match ($parts[0]) {
            'A/u25' => [$this->init($ctx)],
            'A/u271' => [$this->play($ctx, (int) ($parts[1] ?? 0), array_map('intval', explode(':', (string) ($parts[2] ?? ''))))],
            'A/u272', 'A/u274' => [$this->play($ctx, (int) ($state['bet'] ?? 0), array_map('intval', (array) ($state['picks'] ?? [])))],
            'A/u270', 'A/u250' => [$this->frame($ctx, '00', 0.0, (int) ($state['bet'] ?? 0), (array) ($state['picks'] ?? []), (array) ($state['drawn'] ?? []))],
            'A/u350' => ['UPDATE#'.(int) round($ctx->balance() * 100)],
            default => [$this->fmt->error($parts[0], 'unknown command')],
        };
    }

    private function init(GameContext $ctx): string
    {
        $frame = $this->table($ctx)['init'] ?? '';
        if ($frame === '') {
            return $this->fmt->error('A/u25', 'table not configured');
        }
        $i = 4;
        $i += 1 + (int) hexdec($frame[$i]);             // round
        $len = (int) hexdec($frame[$i]);                // balance

        return substr($frame, 0, $i).$this->cents($ctx->balance()).substr($frame, $i + 1 + $len);
    }

    /** @param list<int> $picks */
    private function play(GameContext $ctx, int $betIndex, array $picks): string
    {
        $picks = array_values(array_unique(array_filter($picks, fn ($n) => $n >= 1 && $n <= 80)));
        $pays = (array) ($this->table($ctx)['paytable'] ?? []);
        $betCents = $this->bets($ctx)[$betIndex] ?? null;
        if ($betCents === null || $picks === [] || count($picks) > 10) {
            return $this->fmt->error('A/u271', 'invalid bet/picks');
        }
        $stake = $betCents / 100;
        if ($ctx->balance() < $stake) {
            return $this->fmt->error('A/u271', 'invalid balance');
        }
        $ctx->placeBet($stake);

        $draw = function () {
            $pool = range(1, 80);
            shuffle($pool);

            return array_slice($pool, 0, 20);
        };
        $payout = fn (array $drawn) => (float) ($pays[count($picks)][count(array_intersect($picks, $drawn))] ?? 0) * $stake;
        $drawn = $draw();
        for ($try = 0; $try < 200 && $payout($drawn) > $ctx->bankAvailable(); $try++) {
            $drawn = $draw();
        }
        $win = round($payout($drawn), 2);
        if ($win > $ctx->bankAvailable()) {
            $win = 0.0;   // pool can't cover even after re-draws — pay nothing rather than over-pay
        }
        if ($win > 0) {
            $win = $ctx->awardWin($win);
        }

        $ctx->statePut(['features' => ['keno' => ['bet' => $betIndex, 'picks' => $picks, 'drawn' => $drawn]]]);
        $ctx->recordRound(
            new SpinResult(bet: $stake, win: $win, state: 'bet'),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => ['picks' => $picks, 'drawn' => $drawn, 'totalWin' => $win]]),
        );

        return $this->frame($ctx, '03', $win, $betIndex, $picks, $drawn);
    }

    /**
     * @param  list<int>  $picks
     * @param  list<int>  $drawn
     */
    private function frame(GameContext $ctx, string $state, float $win, int $betIndex, array $picks, array $drawn): string
    {
        [$maxBet, $maxWin, $x] = $this->limits($ctx);
        $out = '1'.$state.'0'.$this->fmt->hexFmt(random_int(1, 0xFFFFFFF)).$this->cents($ctx->balance()).$this->cents($win)
            .$this->fmt->hexFmt($betIndex).$maxBet.$maxWin.$x;
        foreach (array_pad(array_slice($picks, 0, 10), 10, 0) as $n) {
            $out .= $this->fmt->hexFmt($n);
        }
        foreach (array_pad(array_slice($drawn, 0, 22), 22, 0) as $n) {
            $out .= $this->fmt->hexFmt($n);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function table(GameContext $ctx): array
    {
        return (array) ($ctx->config()->bonusConfig()['table'] ?? []);
    }

    /**
     * The captured init read the client's way: round, balance, win, bet,
     * maxBet, maxWin, one more field (raw, hex-formatted), 10 picks, 22
     * numbers — then the bet ladder (cents, comma list) up to `#`.
     *
     * @return array{fields: list<string>, ladder: list<int>}
     */
    private function parsedInit(GameContext $ctx): array
    {
        $init = (string) ($this->table($ctx)['init'] ?? '');
        $i = 4;
        $i += 1 + (int) hexdec($init[$i] ?? '0');
        $fields = [];
        for ($k = 0; $k < 6 + 10 + 22; $k++) {
            $n = (int) hexdec($init[$i] ?? '0');
            $fields[] = substr($init, $i, 1 + $n);
            $i += 1 + $n;
        }
        $rest = substr($init, $i);
        $ladder = array_map('intval', explode(',', strstr($rest, '#', true) ?: $rest));

        return ['fields' => $fields, 'ladder' => array_values(array_filter($ladder, fn ($b) => $b > 0))];
    }

    /** @return list<int> */
    private function bets(GameContext $ctx): array
    {
        return $this->parsedInit($ctx)['ladder'];
    }

    /** maxBet, maxWin and the field after them, as the captured init has them. @return array{0: string, 1: string, 2: string} */
    private function limits(GameContext $ctx): array
    {
        $f = $this->parsedInit($ctx)['fields'];

        return [$f[3] ?? '10', $f[4] ?? '10', $f[5] ?? '10'];
    }

    private function cents(float $amount): string
    {
        return $this->fmt->hexFmt(round($amount * 100));
    }
}
