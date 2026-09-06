<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;

/**
 * Builds the bespoke `key=value&key2=value2` plain-text bodies the real
 * Pragmatic Play HTML5 ("gs2c") client expects — ported field-by-field from
 * the legacy per-game `Server.php` (verified against `AncientEgyptPM`; the
 * other five `*PM` titles share the same package shape). Unlike Playtech's
 * wire format, this one sends the actual resolved symbol grid (`s=`), not raw
 * reel-strip positions — so no offset reconstruction is needed, only the
 * {@see SlotEngine} board.
 *
 * Not faithfully ported (return a graceful no-op instead of the real reply):
 * the pick-a-prize bonus wheel (`doBonus`), the "mystery scatter" respin
 * (`doMysteryScatter`), and the card-draw gamble (`gamble5Get*` — the
 * simpler red/black `slotGamble` is supported). Same class of gap as
 * Amatic's reel-stop animation / EGT's per-code bonus overrides — per-title
 * bespoke mechanics, not solvable generically.
 */
class PragmaticFormatter
{
    /** Credits (not cents) — Pragmatic's own client formats decimals itself. */
    public function credits(GameContext $ctx): float
    {
        $denom = $ctx->config()->denomination();

        return round($denom > 0 ? $ctx->balance() / $denom : $ctx->balance(), 2);
    }

    /** The `doInit` reply: static game config + the current (or last) board. */
    public function init(GameContext $ctx, array $board): string
    {
        $cfg = $ctx->config();
        $bal = $this->credits($ctx);
        $bets = $ctx->betOptions();

        $params = [
            'wsc' => '1~bg~50,10,1,0,0~0,0,0,0,0~fs~50,10,1,0,0~10,10,10,0,0',
            'balance' => $bal,
            'cfgs' => 1,
            'ver' => 2,
            'index' => 1,
            'balance_cash' => $bal,
            'reel_set_size' => 2,
            'balance_bonus' => '0.00',
            'na' => 's',
            'scatters' => '',
            'rt' => 'd',
            'stime' => (int) floor(microtime(true) * 1000),
            'sc' => implode(',', $bets),
            'defc' => $bets[0] ?? 1,
            'sh' => $cfg->rowCount(),
            'bonuses' => 0,
            'c' => $bets[0] ?? 1,
            'sver' => 5,
            'n_reel_set' => 0,
            'counter' => 2,
            'paytable' => $this->paytableCsv($cfg),
            'l' => $cfg->lineCount(),
            'reel_set0' => $this->reelSetString($cfg, false),
            'reel_set1' => $this->reelSetString($cfg, true),
            's' => $this->flattenBoard($cfg, $board),
        ];

        return $this->buildQuery($params);
    }

    /**
     * The `doSpin` reply: post-spin balance + resolved board + win-line
     * strings. `$offsets` (from {@see SpinResult::$extra}`['reel_offsets']`)
     * supplies the one-above/one-below symbols (`sa`/`sb`) the client uses to
     * animate the reel scrolling into its final resting position.
     *
     * @param  array<int,int>  $offsets
     */
    public function spin(GameContext $ctx, SpinResult $result, array $offsets, bool $isFree, int $totalFreeGames, int $currentFreeGame): string
    {
        $cfg = $ctx->config();
        $bal = $this->credits($ctx);

        [$winString, $lineCount] = $this->winLines($result, $cfg);

        $params = [
            'tw' => round($result->win, 2),
            'balance' => $bal,
            'index' => 1,
            'balance_cash' => $bal,
            'balance_bonus' => '0.00',
            'na' => $result->win > 0 ? 'c' : 's',
            'stime' => (int) floor(microtime(true) * 1000),
            'sa' => $this->adjacentRow($cfg, $offsets, $cfg->rowCount()),
            'sb' => $this->adjacentRow($cfg, $offsets, -1),
            'sh' => $cfg->rowCount(),
            'c' => round($result->bet, 2),
            'sver' => 5,
            'n_reel_set' => 0,
            'counter' => 1,
            'l' => $cfg->lineCount(),
            's' => $this->flattenBoard($cfg, $result->reels),
            'w' => round($result->win, 2),
        ];

        if ($isFree) {
            $params['fsmul'] = 1;
            $params['fsmax'] = $totalFreeGames;
            $params['fswin'] = round($result->win, 2);
            $params['fs'] = $currentFreeGame;
        }

        return $this->buildQuery($params).$winString;
    }

    /** The `update` reply: a balance-only poll. */
    public function balanceOnly(GameContext $ctx): string
    {
        return $this->buildQuery(['balance' => $this->credits($ctx)]);
    }

    /** `slotGamble` (red/black double-up) reply — JSON, unlike everything else here. */
    public function gamble(GameContext $ctx, array $g): string
    {
        return json_encode([
            'responseEvent' => 'gambleResult',
            'serverResponse' => [
                'dealerCard' => $g['card'],
                'gambleState' => $g['won'] ? 'win' : 'lose',
                'totalWin' => round($g['after'], 2),
                'afterBalance' => $this->credits($ctx),
                'Balance' => round($g['before'], 2),
            ],
        ]) ?: '{}';
    }

    public function error(string $type, string $message): string
    {
        return json_encode(['responseEvent' => 'error', 'responseType' => $type, 'serverResponse' => $message]) ?: '{}';
    }

    // ---- helpers --------------------------------------------------

    private function buildQuery(array $params): string
    {
        return implode('&', array_map(fn ($k, $v) => "{$k}=".(is_float($v) ? number_format($v, 2, '.', '') : $v), array_keys($params), $params));
    }

    /** Row-major flat symbol list: all reels' row0, then row1, then row2… (legacy `psArr` order). */
    private function flattenBoard(GameConfig $cfg, array $board): string
    {
        $rows = $cfg->rowCount();
        $reels = $cfg->reelCount();
        $flat = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($reel = 0; $reel < $reels; $reel++) {
                $flat[] = $board[$reel][$r] ?? 0;
            }
        }

        return implode(',', $flat);
    }

    /** The strip symbol `$rowOffset` rows from the visible window's top (legacy reel[3] / reel[-1]). */
    private function adjacentRow(GameConfig $cfg, array $offsets, int $rowOffset): string
    {
        $out = [];
        foreach ($cfg->reelStrips(false) as $reel => $strip) {
            $n = max(1, count($strip));
            $at = $offsets[$reel] ?? 0;
            $out[] = $strip[(($at + $rowOffset) % $n + $n) % $n] ?? 0;
        }

        return implode(',', $out);
    }

    private function paytableCsv(GameConfig $cfg): string
    {
        $rows = [];
        foreach ($cfg->symbols() as $sym) {
            $row = $cfg->paytable()[$sym] ?? [];
            // Legacy order: count5..count1 (descending), 5 values, dropping count6.
            $ordered = [$row[4] ?? 0, $row[3] ?? 0, $row[2] ?? 0, $row[1] ?? 0, $row[0] ?? 0];
            $rows[] = implode(',', array_map(fn ($v) => (int) $v, $ordered));
        }

        return implode(';', $rows);
    }

    private function reelSetString(GameConfig $cfg, bool $bonus): string
    {
        $strips = $cfg->reelStrips($bonus);

        return collect($strips)->map(fn ($strip) => implode(',', $strip))->implode('~');
    }

    /**
     * @return array{0: string, 1: int} [querystring of `l<i>=<line>~<win>~<pos1>~…`, count]
     */
    private function winLines(SpinResult $result, GameConfig $cfg): array
    {
        $rows = $cfg->rowCount();
        $reels = $cfg->reelCount();
        $out = '';
        $i = 0;

        foreach ($result->lines as $w) {
            if (($w['line'] ?? -1) < 0) {
                continue;   // scatter — Pragmatic reports it via a separate `psym=` param, not ported here
            }
            $cells = $w['cells'] ?? [];
            $positions = [];
            for ($c = 0; $c < count($cells); $c += 2) {
                $reel = $cells[$c];
                $row = $cells[$c + 1];
                $positions[] = $row * $reels + $reel;
            }
            $out .= "&l{$i}={$w['line']}~".round($w['amount'], 2).'~'.implode('~', $positions);
            $i++;
        }

        return [$out, $i];
    }
}
