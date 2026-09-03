<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * Builds the wire strings the legacy Amatic "amarent" front-end reads — a
 * hand-packed, length-prefixed hex stream (not JSON), faithful to the legacy
 * per-game `Server.php`. The client decodes it with a cursor: read 1 hex digit
 * for a small int, or 1 hex digit = length N then N hex digits = value
 * ({@see hexFmt} is that encoding, legacy `HexFormat`).
 *
 * Money is in "cents": every currency amount on the wire is `round(x * 100)`.
 *
 * @see AmaticProtocol  dispatch + wallet
 */
class AmaticFormatter
{
    /** Legacy `$floatBet` — the wire works in hundredths of a credit. */
    private const int CENTS = 100;

    /** Legacy fixes the grid at 10 lines / 5 reels regardless of the bet. */
    private const int LINES = 10;

    // ---- primitive encoders (legacy HexFormat / dechex helpers) ------

    /** Legacy `HexFormat($n)` = strlen(dechex($n)) . dechex($n). */
    public function hexFmt(int|float $n): string
    {
        $h = dechex((int) round($n));

        return strlen($h).$h;
    }

    /** dechex, left-padded to two chars (legacy `if(strlen<=1) '0'.$x`). */
    private function hex2(int $n): string
    {
        $h = dechex($n);

        return strlen($h) <= 1 ? '0'.$h : $h;
    }

    /** Legacy `FormatReelStrips`: per strip -> strlen(lenHex).lenHex.<sym dechex…>. */
    public function reelStrips(GameConfig $cfg, bool $bonus): string
    {
        $out = '';
        foreach ($cfg->reelStrips($bonus) as $strip) {
            if ($strip === []) {
                continue;
            }
            $body = implode('', array_map(fn ($s) => dechex((int) $s), $strip));
            $len = dechex(count($strip));
            $out .= strlen($len).$len.$body;
        }

        return $out;
    }

    /** Legacy bet list: per bet -> dechex(strlen(dechex(bet*100))).dechex(bet*100). */
    private function betString(array $bets): string
    {
        $out = '';
        foreach ($bets as $b) {
            $v = dechex((int) round($b * self::CENTS));
            $out .= dechex(strlen($v)).$v;
        }

        return $out;
    }

    // ---- reel window ------------------------------------------------

    /**
     * `{reel1:["3","5","1"], …, rp:[p1,…p5]}` — 3 visible rows, plus the strip
     * stop position per reel (found by matching the shown window back to the
     * configured strip, so the client renders the same board we evaluated).
     *
     * @param  array<int, list<int>>  $board  [reel][row] symbol index
     * @return array{reels: array<string, mixed>, rp: list<int>}
     */
    public function reelWindow(array $board, GameConfig $cfg, bool $bonus): array
    {
        $strips = $cfg->reelStrips($bonus);
        $rows = $cfg->rowCount();
        $reels = [];
        $rp = [];

        for ($r = 0; $r < $cfg->reelCount(); $r++) {
            $col = array_map('strval', array_slice($board[$r] ?? [], 0, $rows));
            $reels['reel'.($r + 1)] = $col;
            $rp[] = $this->stripPosition($strips[$r] ?? [], $board[$r] ?? [], $rows);
        }
        $reels['rp'] = $rp;

        return ['reels' => $reels, 'rp' => $rp];
    }

    /** First strip index whose $rows-window equals the shown column (else 0). */
    private function stripPosition(array $strip, array $column, int $rows): int
    {
        $n = count($strip);
        if ($n === 0) {
            return 0;
        }
        $want = array_map('intval', array_slice($column, 0, $rows));
        for ($p = 0; $p < $n; $p++) {
            $ok = true;
            for ($k = 0; $k < $rows; $k++) {
                if ((int) $strip[($p + $k) % $n] !== ($want[$k] ?? -1)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $p;
            }
        }

        return 0;
    }

    private function reelState(array $rp): string
    {
        return implode('', array_map(fn ($p) => $this->hexFmt((int) $p), $rp));
    }

    // ---- packets ---------------------------------------------------

    /**
     * `A/u25` — the init / settings packet (paytable art is in the bundle; this
     * carries reel strips, bets, balance, current bet/lines and free-spin state).
     */
    public function settings(GameContext $ctx, array $state): string
    {
        $cfg = $ctx->config();
        $bets = $ctx->betOptions();
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));

        $rp = $state['rp'] ?? array_fill(0, $cfg->reelCount(), 0);
        $reelState = $this->reelState($rp);

        $curBet = $this->hex2((int) ($state['last_bet_index'] ?? 0));
        $lines = self::LINES;
        $linesHex = $this->hex2($lines);                       // '0a'
        $betCents = array_map(fn ($b) => (int) round($b * self::CENTS), $bets);
        $minBets = $this->hexFmt($betCents[0]);
        $maxBets = $this->hexFmt(end($betCents) * $lines);
        $betsLen = $this->hex2(count($bets));

        $freeTotal = (int) ($state['free_total'] ?? 0);
        $freeLeft = (int) ($state['free_left'] ?? 0);
        $freeInfo = $this->freeInfo($freeTotal, $freeLeft);
        $stateWin = $freeTotal > 0 ? $this->hexFmt(round((float) ($state['bonus_win'] ?? 0) * self::CENTS)) : '10';

        $slotState = $freeLeft > 0 ? '6' : '4';

        return '05'.$this->reelStrips($cfg, false).'5'.$this->reelStrips($cfg, true)
            .'0'.$slotState.'0'.$reelState.'10'.$balance.$stateWin.$curBet.$minBets.$maxBets
            .$linesHex.$freeInfo.'1010101011'.$linesHex.$linesHex.'0a1000'.$reelState
            .'0000000000000000'.$betsLen.$this->betString($bets)
            .'3310101010101010101010101010101010101010101010101010101010101010101010101010101010101010101010101010101010#00101010|0';
    }

    /** `A/u250` — light re-sync (balance + last reel state). */
    public function resync(GameContext $ctx, array $state): string
    {
        $cfg = $ctx->config();
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));
        $rp = $state['rp'] ?? array_fill(0, $cfg->reelCount(), 0);
        $linesHex = $this->hex2(self::LINES);

        return '100010'.$balance.'10'.$this->reelState($rp).'00'.$linesHex
            .'10101010101010101010100b101010101010101010101014311d0c18190208#101010';
    }

    /**
     * `A/u251` — a spin. `$displayBalance` is post-stake / pre-win (the client
     * animates the win on top); `$total` is this step's win, `$bonusMpl` the
     * free-spin multiplier the per-line hex is divided back down by.
     *
     * @param  array<string, mixed>  $state
     * @return array{frame: string, rp: list<int>, double_answer: string, win_hex: string}
     */
    public function spin(GameContext $ctx, SpinResult $r, array $state, bool $isFree): array
    {
        $cfg = $ctx->config();
        $betLine = (float) ($state['last_bet'] ?? 0) ?: 1.0;
        $lines = (int) ($state['last_lines'] ?? $cfg->lineCount());
        $betIndex = (int) ($state['last_bet_index'] ?? 0);
        $bonusMpl = $isFree ? max(1, $cfg->freeSpinsMultiplier()) : 1;

        $window = $this->reelWindow($r->reels, $cfg, $isFree);
        $rp = $window['rp'];
        $reelState = $this->reelState($rp);

        $scatter = $cfg->scatterSymbol();
        $scatterCount = (int) ($r->extra['scatters'][$scatter] ?? 0);

        $displayBalance = $this->hexFmt(round((float) ($state['frozen_balance'] ?? $ctx->balance()) * self::CENTS));
        $stepWin = $isFree ? (float) ($state['bonus_win'] ?? $r->win) : $r->win;

        // per-line win, expressed in "credits" (win / betline / freeMpl), then the scatter total
        $perLine = array_fill(0, self::LINES, 0.0);
        $scatterWin = 0.0;
        foreach ($r->lines as $w) {
            $ln = (int) ($w['line'] ?? -1);
            if ($ln < 0) {
                $scatterWin += (float) ($w['amount'] ?? 0);

                continue;
            }
            if ($ln < self::LINES) {
                $perLine[$ln] += (float) ($w['amount'] ?? 0);
            }
        }
        $winHex = '';
        foreach ($perLine as $amt) {
            $winHex .= $this->hexFmt(round($amt / max($betLine, 0.0001) / $bonusMpl));
        }
        $winHex .= $this->hexFmt(round($scatterWin / max($betLine, 0.0001) / max($lines, 1) / $bonusMpl));

        $freeTotal = (int) ($state['free_total'] ?? 0);
        $freeLeft = (int) ($state['free_left'] ?? 0);
        // regular: total repeated; free spin: total + remaining
        $freeInfo = $isFree ? $this->freeInfo($freeTotal, $freeLeft) : $this->freeInfo($freeTotal, $freeTotal);
        $freeWinState = $isFree && $r->win > 0 ? '19' : '10';

        $gameState = match (true) {
            $scatterCount >= 3 && ! $isFree => '05',
            $isFree && $freeLeft <= 0 => '0c',
            $isFree && $scatterCount >= 3 => '0a',
            $isFree => '06',
            default => '03',
        };

        $cards = str_repeat('00', 6);
        // legacy `$fixedLinesFormated0` is fixed at dechex(10 + 1) — computed once,
        // never recomputed for the actual line count.
        $linesPlus = $this->hex2(self::LINES + 1);   // '0b'
        $doubleAnswer = $reelState.$this->hex2($betIndex).$this->hex2($lines)
            .'1010101010101010101010'.$linesPlus.$winHex;

        $frame = '1'.$gameState.'010'.$displayBalance.$this->hexFmt(round($stepWin * self::CENTS)).$reelState
            .$this->hex2($betIndex).$this->hex2($lines).$freeInfo.$freeWinState.$this->hexFmt($bonusMpl)
            .'1010'.$reelState.$linesPlus.$winHex.$cards.'#'.$scatterCount;
        $frame .= '_'.json_encode($window['reels'], JSON_UNESCAPED_SLASHES);

        return ['frame' => $frame, 'rp' => $rp, 'double_answer' => $doubleAnswer, 'win_hex' => $winHex];
    }

    // ---- gamble ---------------------------------------------------

    /**
     * `A/u257` result — `1 07 010 <balance> <win> <doubleAnswer><cards>`. The
     * client already knows red/black/suit from its own deck; we only report the
     * outcome + the doubled (or zeroed) win.
     *
     * @param  array<string, mixed>  $state
     */
    public function gambleResult(GameContext $ctx, array $state, int $action, bool $won): string
    {
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));
        $win = (float) ($state['gamble_amount'] ?? 0);
        $winHex = dechex((int) round($win * self::CENTS));
        $answer = (string) ($state['double_answer'] ?? '');
        $cards = $this->cards($state, $action, $won);

        return '107010'.$balance.strlen($winHex).$winHex.$answer.$cards;
    }

    /** `A/u258` — same shape, header `1 08 010`. */
    public function gambleHalf(GameContext $ctx, array $state): string
    {
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));
        $win = (float) ($state['gamble_amount'] ?? 0);
        $winHex = dechex((int) round($win * self::CENTS));
        $answer = (string) ($state['double_answer'] ?? '');

        return '108010'.$balance.strlen($winHex).$winHex.$answer.str_repeat('00', 6);
    }

    /** Six-card gamble history, newest first — one fresh card pushed each round. */
    private function cards(array $state, int $action, bool $won): string
    {
        // legacy 54-card deck: red 0,1,4,5,… black 2,3,6,7,… suits mod 4
        $pick = match (true) {
            $action <= 2 => random_int(0, 26) * 2 + (($won === ($action === 1)) ? 0 : 1),
            default => random_int(0, 12) * 4 + (($action - 3)),
        };
        $card = dechex(min(53, max(0, $pick)));
        $card = strlen($card) <= 1 ? '0'.$card : $card;

        $history = (array) ($state['cards'] ?? array_fill(0, 6, '00'));
        array_unshift($history, $card);
        $history = array_slice($history, 0, 6);

        return implode('', $history);
    }

    /** Collect: `1 04 010 <balance> <win> <reelState><bet><lines>…<winHex><cards>#101010`. */
    public function collect(GameContext $ctx, array $state): string
    {
        $cfg = $ctx->config();
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));
        $rp = $state['rp'] ?? array_fill(0, $cfg->reelCount(), 0);
        $reelState = $this->reelState($rp);
        $win = $this->hexFmt(round((float) ($state['total_win'] ?? 0) * self::CENTS));
        $winHex = (string) ($state['win_hex'] ?? str_repeat('10', self::LINES + 1));
        $betIndex = $this->hex2((int) ($state['last_bet_index'] ?? 0));
        $lines = $this->hex2((int) ($state['last_lines'] ?? self::LINES));

        return '104010'.$balance.$win.$reelState.$betIndex.$lines
            .'1010101010101010101010'.$this->hex2(self::LINES + 1).$winHex.str_repeat('00', 6).'#101010';
    }

    /** `A/u350` — the 5-second balance poll. */
    public function balancePoll(GameContext $ctx, array $state): string
    {
        $win = (float) ($state['total_win'] ?? 0);

        return 'UPDATE#'.(int) round(($ctx->balance() - $win) * self::CENTS);
    }

    // ---- helpers -------------------------------------------------

    private function freeInfo(int $total, int $current): string
    {
        $t = dechex(max(0, $total));
        $c = dechex(max(0, $current));

        return strlen($t).$t.strlen($c).$c;
    }

    public function error(string $type, string $message): string
    {
        return json_encode([
            'responseEvent' => 'error',
            'responseType' => $type,
            'serverResponse' => $message,
        ], JSON_UNESCAPED_SLASHES);
    }
}
