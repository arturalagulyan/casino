<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Engine\TumbleEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;

/**
 * Builds the `key=value&…` plain-text bodies for the modern Pragmatic Play
 * "gs2c" tumble/scatter-pays family — ported field-for-field from the legacy
 * `VanguardLTE\Games\BonanzaGold\PragmaticLib\LogAndServer::getResult` (the
 * exact server the real client bundle was written against).
 *
 * Not faithfully ported: the "AddFreeSpin" mid-bonus scatter retrigger's own
 * wire fields, and the double-chance/ante-bet side mode (`bl`, always sent as
 * 0). Same class of gap as every other provider's per-title bonus overrides.
 */
class PragmaticTumbleFormatter
{
    /**
     * The wallet balance, verbatim — the real Pragmatic client has no
     * separate "denomination" concept of its own; every money field it's
     * sent (balance, bet ladder) is the real stake in the player's
     * currency, displayed as-is.
     */
    public function credits(GameContext $ctx): float
    {
        return round($ctx->balance(), 2);
    }

    /**
     * `bet_options` re-priced into the player's currency (see
     * {@see CurrencyScaler}) — the client shows these numbers directly with
     * no further conversion of its own.
     *
     * @return list<float>
     */
    private function scaledBets(GameContext $ctx): array
    {
        $denom = $ctx->config()->denomination();

        return array_map(fn (float $b) => round($b * $denom, 2), $ctx->betOptions());
    }

    /**
     * The `doInit` reply: the game's raw legacy config replayed verbatim
     * (it's already the exact spec the real client expects) plus the
     * dynamic balance/board fields. Always shows a fresh (never a resumed)
     * board — simpler and safe; a reconnecting player just sees a new deal.
     */
    public function init(GameContext $ctx, GameConfig $cfg, array $board): string
    {
        $bal = $this->credits($ctx);
        $bets = $this->scaledBets($ctx);
        $defc = $bets[0] ?? 1.0;

        $fresh = [
            'stime='.(int) floor(microtime(true) * 1000),
            'balance='.number_format($bal, 2, '.', ''),
            'balance_cash='.number_format($bal, 2, '.', ''),
            'def_s='.implode(',', $board['slotArea']),
            's='.implode(',', $board['slotArea']),
            'sa='.implode(',', $board['symbolsAfter']),
            'sb='.implode(',', $board['symbolsBelow']),
            'bl=0',
            'sc='.implode(',', $bets),
            'defc='.$defc,
            'c='.$defc,
            'l='.$cfg->tumbleConfig()['lines'],
        ];

        $params = array_merge($this->stripKeys($cfg->tumbleConfig()['raw'], $fresh), $fresh);

        return implode('&', $params);
    }

    /**
     * Drop any `raw` entry whose key also appears in `$fresh` — `raw` is the
     * legacy captured config replayed verbatim, which bakes in its own
     * (denomination-1, stale-board) values for fields we recompute per
     * request. Left alone, both copies land in the query string and the real
     * client's `URLSearchParams`-style parser keeps whichever is FIRST — the
     * stale raw one — silently discarding our scaled bet ladder / fresh board
     * (surfaced as currency-scaled bets never taking effect for non-base-
     * currency players).
     *
     * @param  list<string>  $raw
     * @param  list<string>  $fresh
     * @return list<string>
     */
    private function stripKeys(array $raw, array $fresh): array
    {
        $keys = array_map(fn (string $line) => explode('=', $line, 2)[0], $fresh);

        return array_values(array_filter(
            $raw,
            fn (string $line) => ! in_array(explode('=', $line, 2)[0], $keys, true),
        ));
    }

    /** @param  array<string,mixed>  $state  {@see TumbleEngine::step()}'s return */
    public function spin(GameContext $ctx, GameConfig $cfg, array $state, float $betline, int $index, int $counter): string
    {
        $bal = $this->credits($ctx);
        $state_ = $state['State'];

        $params = [
            'tw' => $state['TotalWin'],
            'prg_m' => 'wm',
            'balance' => $bal,
            'prg' => 1,
            'index' => $index,
            'balance_cash' => $bal,
            'reel_set' => $this->inFreeSpins($state) ? 1 : 0,
            'balance_bonus' => '0.00',
            'na' => $state_ === 'LastRespin' ? 'c' : 's',
            'bl' => 0,
            'stime' => (int) floor(microtime(true) * 1000),
            'sa' => implode(',', $state['SymbolsAfter']),
            'sb' => implode(',', $state['SymbolsBelow']),
            'sh' => $cfg->rowCount(),
            'c' => $betline,
            'sver' => 5,
            'counter' => $counter,
            'l' => $cfg->tumbleConfig()['lines'],
            's' => implode(',', $state['SlotArea']),
            'w' => $state['Win'],
        ];

        $out = $this->buildQuery($params);

        if (in_array($state_, ['FirstRespin', 'Respin'], true) && $state['Win'] > 0) {
            $out .= $this->positions($state['WinLines']);
            $out .= $this->buildQuery(array_merge(
                $state_ === 'FirstRespin' ? ['rs' => 't', 'rs_p' => 0] : ['rs_p' => $state['Respin']],
                ['rs_c' => 1, 'rs_m' => 1, 'tmb_win' => $state['TotalWin']],
                $state_ === 'Respin' ? ['rs_win' => $state['RespinWin']] : [],
            ), true);
        }

        if ($state_ === 'LastRespin') {
            $out .= $this->buildQuery([
                'rs_t' => $state['Respin'],
                'rs_win' => $state['RespinWin'],
                'tmb_res' => $state['TotalWin'],
                'tmb_win' => $state['TotalWin'],
            ], true);
        }

        if (isset($state['FSPay'])) {
            $out .= '&psym='.$state['Scatter'].'~'.$state['FSPay'].'~'.implode(',', $state['ScatterPositions']);
        }

        if (isset($state['FreeSpinNumber']) && $state['FreeState'] !== 'LastFreeSpin') {
            $out .= $this->buildQuery([
                'fsmul' => 1, 'fsmax' => $state['FreeSpins'], 'fswin' => '0.00',
                'fs' => $state['FreeSpinNumber'], 'fsres' => '0.00',
            ], true);
        } elseif (($state['FreeState'] ?? null) === 'LastFreeSpin') {
            $out .= $this->buildQuery([
                'fsmul_total' => 1, 'fswin_total' => '0.00',
                'fs_total' => $state['FreeSpinNumber'] - 1, 'fsres_total' => '0.00', 'fs_bought' => 10,
            ], true);
        }

        if (isset($state['Multipliers'])) {
            $rmul = implode(';', array_map(
                fn ($m) => $m['symbol'].'~'.$m['position'].'~'.$m['multiplier'],
                $state['Multipliers'],
            ));
            $out .= '&rmul='.$rmul;
            if (isset($state['MultiplierApplied'])) {
                $out = preg_replace('/(^|&)prg=\d+/', '${1}prg='.$state['MultiplierApplied'], $out, 1) ?? $out;
            }
        }

        return $out;
    }

    public function balanceOnly(GameContext $ctx): string
    {
        return $this->buildQuery(['balance' => $this->credits($ctx), 'balance_cash' => $this->credits($ctx)]);
    }

    public function error(string $type, string $message): string
    {
        return json_encode(['responseEvent' => 'error', 'responseType' => $type, 'serverResponse' => $message]) ?: '{}';
    }

    // ---- helpers -----------------------------------------------------

    private function inFreeSpins(array $state): bool
    {
        return array_key_exists('FreeSpinNumber', $state) && $state['FreeState'] !== 'LastFreeSpin';
    }

    private function buildQuery(array $params, bool $leadingAmp = false): string
    {
        $q = implode('&', array_map(
            fn ($k, $v) => "{$k}=".(is_float($v) ? number_format($v, 2, '.', '') : $v),
            array_keys($params), $params,
        ));

        return $leadingAmp ? '&'.$q : $q;
    }

    /** @param  list<array{symbol:int,count:int,pay:float,positions:list<int>}>  $winLines */
    private function positions(array $winLines): string
    {
        $out = '';
        $tmb = [];
        foreach ($winLines as $i => $line) {
            $out .= '&l'.$i.'=0~'.number_format($line['pay'], 2, '.', '').'~'.implode('~', $line['positions']);
            // Legacy: `implode(','.$symbol.'~', $positions)` — a compound
            // separator, so every position except the line's last is
            // tagged "<pos>,<symbol>~"; that last position carries no tag
            // (verbatim legacy quirk, not a bug to "fix" here).
            $tmb[] = implode(','.$line['symbol'].'~', $line['positions']);
        }
        $out .= '&tmb='.implode('~', $tmb);

        return $out;
    }
}
