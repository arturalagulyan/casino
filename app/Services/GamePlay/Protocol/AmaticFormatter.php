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

    /** Fallback line count (legacy `$lines` is each game's fixed line count). */
    private const int LINES = 10;

    /** Coin-booster stake factor (×100) sent to "coins" clients — 1.00: we run no coin feature to buy. */
    private const int COIN_BOOSTER = 100;

    /** Per-reel coin-value slots the "coins" client reads (5 reels × 10). */
    private const int COIN_SLOTS = 10;

    /** Reel slots on the wire: 3-reel games still send 5 (the last two empty). */
    private const int WIRE_REELS = 5;

    /**
     * Gamble-card history slots on the wire (legacy `<Code>Cards` is 8 × '00').
     * The client reads exactly this many — fewer shifts every field after it.
     */
    private const int CARDS = 8;

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

    /**
     * The free-spin special symbol field. "None" is `0` to the old amarent
     * client (legacy never picks symbol 0) but `0xFFFFFFFF` to the gmsl client,
     * which would otherwise treat symbol 0 as the special one.
     *
     * @param  array<string, mixed>  $state
     */
    private function freeSymbol(array $state, bool $gmsl): string
    {
        $symbol = $state['free_symbol'] ?? null;

        return $this->hexFmt($symbol !== null ? (int) $symbol : ($gmsl ? 0xFFFFFFFF : 0));
    }

    /**
     * Legacy `FormatReelStrips`: per strip -> strlen(lenHex).lenHex.<sym dechex…>.
     * Newer clients with symbols past 15 read two hex digits per symbol; 3-reel
     * games pad the 5 slots with empty strips.
     */
    public function reelStrips(GameConfig $cfg, bool $bonus): string
    {
        $wide = $cfg->amaticClient()['digits'] === 2;
        $out = '';
        $n = 0;
        foreach ($cfg->reelStrips($bonus) as $strip) {
            if ($strip === []) {
                continue;
            }
            $body = implode('', array_map(fn ($s) => $wide ? $this->hex2((int) $s) : dechex((int) $s), $strip));
            $len = dechex(count($strip));
            $out .= strlen($len).$len.$body;
            $n++;
        }

        return $out.str_repeat('0', max(0, self::WIRE_REELS - $n));
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
            $col = array_map('strval', array_slice($board[$r] ?? [], 0, $cfg->reelHeight($r)));
            $reels['reel'.($r + 1)] = $col;
            $rp[] = $this->stripPosition($strips[$r] ?? [], $board[$r] ?? [], min($rows, $cfg->reelHeight($r)));
        }
        $reels['rp'] = $rp;

        return ['reels' => $reels, 'rp' => $rp];
    }

    /**
     * Reel position of the shown column — the strip index of its *second* row
     * (legacy `GetReelStrips`: rows = strip[rp-1], strip[rp], strip[rp+1]).
     * The gmsl client rebuilds the board from these positions the same way
     * and re-evaluates the lines on it; off by one row, every paid line
     * "doesn't exist" on its board and the win animation crashes.
     */
    private function stripPosition(array $strip, array $column, int $rows): int
    {
        $n = count($strip);

        return $n === 0 ? 0 : ($this->windowStart($strip, $column, $rows) + 1) % $n;
    }

    /** First strip index whose $rows-window equals the shown column (else 0). */
    private function windowStart(array $strip, array $column, int $rows): int
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

    /**
     * The "lines" field the client reads. Ways games (legacy `$symCount`)
     * send their paying-symbol count instead: the client lists one win per
     * symbol where a line game lists one per line.
     */
    private function wireLines(GameConfig $cfg): int
    {
        return $cfg->isWays()
            ? count($cfg->waysConfig()['symbols'] ?? [])
            : ($cfg->lineCount() ?: self::LINES);
    }

    /** Entries in the win list: lines + the scatter (ways: legacy `$symCountAll`, + scatter only if the game has one). */
    private function winSlots(GameConfig $cfg): int
    {
        return $cfg->isWays()
            ? $this->wireLines($cfg) + ($cfg->scatterSymbol() !== null ? 1 : 0)
            : $this->wireLines($cfg) + 1;
    }

    private function isCoins(GameConfig $cfg): bool
    {
        return $cfg->clientLayout() === 'coins';
    }

    /**
     * What this game's client reads after the cards of a spin/collect/gamble
     * frame: the tail captured from Amatic's own server at import, else the
     * built-in "coins" one, else nothing.
     */
    private function spinTail(GameConfig $cfg, ?array $board = null): string
    {
        // a board-wild-multiplier game's tail is that multiplier, two hex digits
        if ($board !== null && $cfg->wildBoardMult() !== null) {
            return sprintf('%02x', min(255, $cfg->boardWildMultiplier($board)));
        }
        // a random-wilds game's tail: its prefix, then per reel the row count and each cell's mark (f = none)
        if ($board !== null && ($rw = $cfg->randomWilds()) !== null) {
            return $rw['prefix'].implode('', array_map(fn ($col) => $this->hexFmt(count($col)).implode('', array_map(fn ($m) => $m ?? 'f', $col)),
                $cfg->randomWildCells($board)));
        }
        // a per-reel-wild-multiplier game's tail: its prefix, the reel count and each reel's draw, its suffix
        if ($board !== null && ($rv = $cfg->wildReelValues()) !== null) {
            $draw = (array) $cfg->wildReelDraw($board);

            return $rv['prefix'].$this->hexFmt(count($draw)).implode('', array_map('dechex', $draw)).$rv['suffix'];
        }
        // a full-wild-reel game's tail is the multiplier drawn (1 = none)
        if ($board !== null && $cfg->wildReelMult() !== null) {
            return $this->hexFmt($cfg->wildReelMultiplier($board));
        }
        // a cell-multiplier game's tail is, per reel, the row count then one digit per cell
        if ($board !== null && $cfg->cellMult() !== null) {
            return implode('', array_map(fn ($col) => $this->hexFmt(count($col)).implode('', array_map('dechex', $col)),
                $cfg->cellMultipliers($board)));
        }
        // a giant-wild game's tail is every cell's block value (0 = not covered), reel by reel
        if ($board !== null && ($block = $cfg->wildBlock()) !== null) {
            return implode('', array_map(fn ($v) => $this->hexFmt($v),
                array_merge(...GameConfig::blockCells($board, $block['symbol'], $block['size']))));
        }
        // a wild-copy game's tail: per reel 01, or 00 on the reel a full wild copied onto
        if ($board !== null && $cfg->wildReelCopy() !== null) {
            $target = $cfg->wildCopyTarget($board);

            return implode('', array_map(fn ($r) => $r === $target ? '00' : '01', array_keys(array_values($board))));
        }
        // a mystery-symbol game's tail is what its mystery symbol turned into
        if ($board !== null && ($mystery = $cfg->mystery()) !== null) {
            // reel groups: the reel count, then each reel's symbol (0 off the groups)
            if (($perReel = $cfg->mysteryReelTargets($board)) !== null) {
                return $this->hexFmt(count($perReel)).implode('', array_map(fn ($s) => dechex($s ?? 0), $perReel)).$mystery['suffix'];
            }

            return $this->hexFmt((int) $cfg->mysteryTarget($board));
        }

        return $cfg->amaticClient()['spin_tail'] ?? $this->coinTail($cfg);
    }

    /**
     * The "coins" client's tail after the cards (its `YAP`): the active
     * booster index (`0f` = off) and 5 × 10 coin values. No coin feature
     * runs here, so they're placeholders.
     */
    private function coinTail(GameConfig $cfg): string
    {
        if (! $this->isCoins($cfg)) {
            return '';
        }
        $reel = $this->hexFmt(self::COIN_SLOTS).str_repeat('01', self::COIN_SLOTS);

        return '0f'.str_repeat($reel, $cfg->reelCount());
    }

    /**
     * Main reel positions: the played reels, then — for games with an overlay
     * layer (Extra Gifts/Chillis: a second 5-strip set of mostly blanks) —
     * each overlay reel parked on an all-blank window, so its feature never shows.
     */
    private function positions(GameConfig $cfg, array $rp): string
    {
        return $this->reelState($rp).implode('', array_map(fn ($p) => $this->hexFmt((int) $p), $cfg->amaticClient()['overlay_rp']));
    }

    /** Overlay strips (after the played reels' 5 slots). */
    private function overlayStrips(GameConfig $cfg): string
    {
        $wide = $cfg->amaticClient()['digits'] === 2;
        $out = '';
        foreach ($cfg->amaticClient()['overlay_strips'] as $strip) {
            $len = dechex(count($strip));
            $out .= strlen($len).$len.implode('', array_map(fn ($s) => $wide ? $this->hex2((int) $s) : dechex((int) $s), $strip));
        }

        return $out;
    }

    private function reelState(array $rp): string
    {
        $rp = array_pad(array_values($rp), self::WIRE_REELS, 0);

        return implode('', array_map(fn ($p) => $this->hexFmt((int) $p), $rp));
    }

    // ---- packets ---------------------------------------------------

    /**
     * `A/u25` — the init / settings packet (paytable art is in the bundle; this
     * carries reel strips, bets, balance, current bet/lines and free-spin state).
     */
    public function settings(GameContext $ctx, array $state, bool $gmsl = false): string
    {
        $cfg = $ctx->config();
        // `bet_options` re-priced into the player's currency (see
        // {@see CurrencyScaler}) — the client shows these numbers directly,
        // and `A/u251`'s betIndex still selects into this same list.
        $bets = array_map(fn (float $b) => $b * $cfg->denomination(), $ctx->betOptions());
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));

        $rp = $state['rp'] ?? array_fill(0, $cfg->reelCount(), 0);
        $reelState = $this->reelState($rp);

        $curBet = $this->hex2((int) ($state['last_bet_index'] ?? 0));
        $linesHex = $this->hex2($this->wireLines($cfg));       // '0a' (ways: '09' …)
        $betCents = array_map(fn ($b) => (int) round($b * self::CENTS), $bets);
        $minBets = $this->hexFmt($betCents[0]);
        // the bet ladder is per line; a ways game's stake is the bet itself
        $maxBets = $this->hexFmt(end($betCents) * ($cfg->isWays() ? 1 : $this->wireLines($cfg)));
        $betsLen = $this->hex2(count($bets));

        $freeTotal = (int) ($state['free_total'] ?? 0);
        $freeLeft = (int) ($state['free_left'] ?? 0);
        $freeInfo = $this->freeInfo($freeTotal, $freeLeft);
        $stateWin = $freeTotal > 0 ? $this->hexFmt(round((float) ($state['bonus_win'] ?? 0) * self::CENTS)) : '10';

        $slotState = $freeLeft > 0 ? '6' : '4';

        // Games with a free-spin special symbol (e.g. AztecSecret) end the
        // packet with that symbol instead of the usual trailer.
        // Ways games end with an empty win list sized like their spin's
        // (as Amatic's own server sends it).
        // "Coins" games read an exact-length win list, then the booster cap
        // (none), booster factor and the coin tail.
        $client = $cfg->amaticClient();
        $slots = $client['win_slots'] ?? $this->winSlots($cfg);
        $trailer = match (true) {
            $client['init_tail'] !== null && $cfg->freeSymbolCandidates() === [] => $this->hex2($slots).str_repeat('10', $slots).$client['init_tail'].'#00101010|0',
            $this->isCoins($cfg) => $this->hex2($this->winSlots($cfg)).str_repeat('10', $this->winSlots($cfg))
                .'10'.$this->hexFmt(self::COIN_BOOSTER).$this->coinTail($cfg).'#00101010|0',
            $cfg->freeSymbolCandidates() !== [] => '0a'.str_repeat('10', 10).$this->freeSymbol($state, $gmsl),
            $cfg->isWays() => $this->hex2($this->winSlots($cfg)).str_repeat('10', $this->winSlots($cfg)).'#00101010|0',
            default => '3310101010101010101010101010101010101010101010101010101010101010101010101010101010101010101010101010101010#00101010|0',
        };

        $stripSlots = dechex(self::WIRE_REELS + count($cfg->amaticClient()['overlay_strips']));

        return '0'.$stripSlots.$this->reelStrips($cfg, false).$this->overlayStrips($cfg).'5'.$this->reelStrips($cfg, true)
            .'0'.$slotState.'0'.$this->positions($cfg, $rp).'10'.$balance.$stateWin.$curBet.$minBets.$maxBets
            .$linesHex.$freeInfo.'1010101011'.$linesHex.$linesHex.'0a1000'.$reelState
            .'0000000000000000'.$betsLen.$this->betString($bets)
            .$trailer;
    }

    /** `A/u250` — light re-sync (balance + last reel state). */
    public function resync(GameContext $ctx, array $state): string
    {
        $cfg = $ctx->config();
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));
        $rp = $state['rp'] ?? array_fill(0, $cfg->reelCount(), 0);
        $linesHex = $this->hex2($this->wireLines($cfg));

        return '100010'.$balance.'10'.$this->positions($cfg, $rp).'00'.$linesHex
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
    public function spin(GameContext $ctx, SpinResult $r, array $state, bool $isFree, bool $gmsl = false): array
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

        // per-line win (ways: per paying symbol), expressed in "credits"
        // (win / betline / freeMpl), then the scatter total
        $wireLines = $this->wireLines($cfg);
        $perLine = array_fill(0, $wireLines, 0.0);
        $scatterWin = 0.0;
        foreach ($r->lines as $w) {
            $ln = (int) ($w['line'] ?? -1);
            if ($ln < 0) {
                $scatterWin += (float) ($w['amount'] ?? 0);

                continue;
            }
            if ($ln < $wireLines) {
                $perLine[$ln] += (float) ($w['amount'] ?? 0);
            }
        }
        $winHex = '';
        foreach ($perLine as $amt) {
            $winHex .= $this->hexFmt(round($amt / max($betLine, 0.0001) / $bonusMpl));
        }
        if ($this->winSlots($cfg) > $wireLines) {
            $winHex .= $this->hexFmt(round($scatterWin / max($betLine, 0.0001) / max($lines, 1) / $bonusMpl));
        }
        // a ways game's own "lines" field is its symbol count, not the 1 line it stakes
        $linesField = $cfg->isWays() ? $wireLines : $lines;

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
            // The gmsl client reads 03 as "no win" and stalls if a win comes
            // with it; Amatic's own server answers a base-game win with 09
            // ("win — collect or gamble").
            $gmsl && $r->win > 0 => '09',
            default => '03',
        };

        $cards = str_repeat('00', self::CARDS);
        // Games with a free-spin special symbol (e.g. AztecSecret) read one
        // more field after the cards. Without it their spin parser runs into
        // the `#` and the reels never stop.
        $freeSymbol = $cfg->freeSymbolCandidates() !== [] ? $this->freeSymbol($state, $gmsl) : '';
        // legacy `$fixedLinesFormated0` is fixed at dechex(10 + 1) — computed once,
        // never recomputed for the actual line count (ways: `$symCountAll`).
        $linesPlus = $this->hex2($this->winSlots($cfg));   // '0b'
        $doubleAnswer = $this->positions($cfg, $rp).$this->hex2($betIndex).$this->hex2($linesField)
            .'1010101010101010101010'.$linesPlus.$winHex;

        $frame = '1'.$gameState.'010'.$displayBalance.$this->hexFmt(round($stepWin * self::CENTS)).$this->positions($cfg, $rp)
            .$this->hex2($betIndex).$this->hex2($linesField).$freeInfo.$freeWinState.$this->hexFmt($bonusMpl)
            .'1010'.$reelState.$linesPlus.$winHex.$cards.$freeSymbol.($freeSymbol === '' ? $this->spinTail($cfg, $r->reels) : '');
        // The old amarent client reads `#<scatters>_<reels JSON>`; the gmsl
        // client decodes three more hex fields after `#` and derives the board
        // from the strip positions itself (as captured from Amatic's own server).
        $frame .= $gmsl
            ? '#101010'
            : '#'.$scatterCount.'_'.json_encode($window['reels'], JSON_UNESCAPED_SLASHES);

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

        return '107010'.$balance.strlen($winHex).$winHex.$answer.$cards.$this->spinTail($ctx->config());
    }

    /** `A/u258` — same shape, header `1 08 010`. */
    public function gambleHalf(GameContext $ctx, array $state): string
    {
        $balance = $this->hexFmt(round($ctx->balance() * self::CENTS));
        $win = (float) ($state['gamble_amount'] ?? 0);
        $winHex = dechex((int) round($win * self::CENTS));
        $answer = (string) ($state['double_answer'] ?? '');

        return '108010'.$balance.strlen($winHex).$winHex.$answer.str_repeat('00', self::CARDS).$this->spinTail($ctx->config());
    }

    /** Gamble-card history, newest first — one fresh card pushed each round. */
    private function cards(array $state, int $action, bool $won): string
    {
        // legacy 54-card deck: red 0,1,4,5,… black 2,3,6,7,… suits mod 4
        $pick = match (true) {
            $action <= 2 => random_int(0, 26) * 2 + (($won === ($action === 1)) ? 0 : 1),
            default => random_int(0, 12) * 4 + (($action - 3)),
        };
        $card = dechex(min(53, max(0, $pick)));
        $card = strlen($card) <= 1 ? '0'.$card : $card;

        $history = (array) ($state['cards'] ?? array_fill(0, self::CARDS, '00'));
        array_unshift($history, $card);
        $history = array_slice($history, 0, self::CARDS);

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
        $winHex = (string) ($state['win_hex'] ?? str_repeat('10', $this->winSlots($cfg)));
        $betIndex = $this->hex2((int) ($state['last_bet_index'] ?? 0));
        $lines = $this->hex2($cfg->isWays() ? $this->wireLines($cfg) : (int) ($state['last_lines'] ?? $this->wireLines($cfg)));

        return '104010'.$balance.$win.$this->positions($cfg, $rp).$betIndex.$lines
            .'1010101010101010101010'.$this->hex2($this->winSlots($cfg)).$winHex.str_repeat('00', self::CARDS)
            .$this->spinTail($cfg).'#101010';
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
