<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Engine\PaylineEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\Legacy\PaylineGameParser;

/**
 * Builds the `key=value&…` plain-text bodies for the modern Pragmatic Play
 * "gs2c" classic-payline family — ported field-for-field from the legacy
 * `VanguardLTE\Games\AztecKing\PragmaticLib\LogAndServer::getResult`.
 *
 * Not faithfully ported (per {@see PaylineEngine}'s
 * docblock): the "collector" symbol overlay (`msr`/`ep`/`stf`) always reports
 * the game's static default `msr`, never an active expand/sticky event; the
 * "ea"/"ma" money-symbol variants aren't evaluated at all.
 */
class PragmaticPaylineFormatter
{
    public function credits(GameContext $ctx): float
    {
        $denom = $ctx->config()->denomination();

        return round($denom > 0 ? $ctx->balance() / $denom : $ctx->balance(), 2);
    }

    /** The `doInit` reply: the game's raw legacy config replayed verbatim plus a fresh board + balance. */
    public function init(GameContext $ctx, GameConfig $cfg, array $board): string
    {
        $bal = $this->credits($ctx);
        $bets = $ctx->betOptions();
        $defc = $bets[0] ?? 1.0;
        $raw = $cfg->paylineConfig()['raw'];

        $params = array_merge($raw, $this->missingReelSetFields($cfg, $raw), [
            'stime='.(int) floor(microtime(true) * 1000),
            'balance='.number_format($bal, 2, '.', ''),
            'balance_cash='.number_format($bal, 2, '.', ''),
            'def_s='.implode(',', $board['slotArea']),
            's='.implode(',', $board['slotArea']),
            'sa='.implode(',', $board['symbolsAfter']),
            'sb='.implode(',', $board['symbolsBelow']),
            'defc='.$defc,
            'c='.$defc,
        ]);

        return implode('&', $params);
    }

    /** @param  array<string,mixed>  $state  {@see PaylineEngine::step()}'s return */
    public function spin(GameContext $ctx, GameConfig $cfg, array $state, float $betline, int $lines, int $index, int $counter): string
    {
        $bal = $this->credits($ctx);
        $inFreeSpins = ($state['FreeState'] ?? null) === 'FreeSpin';
        $justEnded = ($state['FreeState'] ?? null) === 'LastFreeSpin';
        $na = ($inFreeSpins || $justEnded) ? 's' : 'c';

        $params = [
            'tw' => $state['TotalWin'],
            'balance' => $bal,
            'index' => $index,
            'balance_cash' => $bal,
            'balance_bonus' => '0.00',
            'na' => $na,
            'stime' => (int) floor(microtime(true) * 1000),
            'sa' => implode(',', $state['SymbolsAfter']),
            'sb' => implode(',', $state['SymbolsBelow']),
            'sh' => $cfg->rowCount(),
            'c' => $betline,
            'sver' => 5,
            'counter' => $counter,
            'l' => $lines,
            's' => implode(',', $state['SlotArea']),
            'w' => $state['Win'],
            'reel_set' => $inFreeSpins || $justEnded ? 1 : 0,
            'msr' => $this->defaultMsr($cfg),
        ];

        $out = $this->buildQuery($params);
        $out .= $this->moneyFields($cfg, $state);

        if (isset($state['TriggerScatterCount'])) {
            $out .= $this->buildQuery(['fsmul' => $state['fsmul'], 'fsmax' => $state['fsmax'], 'fswin' => '0.00', 'fs' => 1, 'fsres' => '0.00'], true);
        } elseif ($inFreeSpins) {
            $out .= $this->buildQuery([
                'fsmul' => $state['fsmul'], 'fsmax' => $state['fsmax'], 'fswin' => $state['fswin'],
                'fs' => $state['fs'], 'fsres' => $state['fswin'],
            ], true);
        } elseif ($justEnded) {
            $out .= $this->buildQuery([
                'fs_total' => $state['fs_total'], 'fswin_total' => $state['fswin_total'],
                'fsmul_total' => $state['fsmul_total'], 'fsres_total' => $state['fswin_total'],
                'rs' => 't', 'rs_p' => 0, 'rs_c' => 1, 'rs_m' => 1,
            ], true);
        }

        if (! empty($state['Lines'])) {
            $out .= $this->positions($state['Lines']);
        }

        return $out;
    }

    /** The `doMysteryScatter` reply (legacy `DoMysteryScatter::doMystery`). */
    public function mysteryScatter(array $state): string
    {
        return $this->buildQuery([
            'fsmul' => 1, 'fsmax' => $state['fsmax'], 'ms' => $state['MysterySymbol'],
            'purtr' => 1, 'reel_set' => 14, 'na' => 's', 'fswin' => '0', 'puri' => 0,
            'fs' => 1, 'fsres' => '0',
        ]);
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

    /**
     * The real gs2c client independently parses this same `doInit` blob to
     * build its own `Vars.ReelSets` object — for titles whose legacy
     * `init.php` stored reels as separate `reel0`, `reel1`, … keys (see
     * {@see PaylineGameParser::reelStrips()}), the raw config replayed
     * verbatim never contains a `reel_set0`/`reel_set1` field. The client's
     * `VSProtocolParser.ParseReelSets` also gates on a *separate*
     * `reel_set_size` count field before it even looks at `reel_setN`, and
     * `VsInitData.ReelSymbols` is a *computed* property that — once
     * `ReelSets` is non-null — ignores the `reel0`/`reel1` fields entirely
     * and instead indexes `ReelSets[ReelSetIndexCurrent]`, which parses
     * from a *third*, bare `reel_set=` field (defaulting to -1, i.e.
     * `ReelSets[-1]` = `undefined`, when absent). All three are missing
     * together for this shape and must be synthesized together, or the
     * client crashes on `doInit` itself instead of on the first spin.
     *
     * @param  list<string>  $raw
     * @return list<string>
     */
    private function missingReelSetFields(GameConfig $cfg, array $raw): array
    {
        $out = [];
        $sets = 0;
        foreach ([0 => false, 1 => true] as $set => $bonus) {
            $present = false;
            foreach ($raw as $line) {
                if (str_starts_with($line, "reel_set{$set}=")) {
                    $present = true;
                    break;
                }
            }
            $sets++;

            if (! $present) {
                $reels = $cfg->reelStrips($bonus);
                if ($reels !== []) {
                    $out[] = "reel_set{$set}=".implode('~', array_map(
                        fn (array $strip) => implode(',', $strip),
                        $reels,
                    ));
                }
            }
        }

        if ($out === []) {
            return $out;
        }

        $existingKeys = array_map(fn (string $line) => explode('=', $line, 2)[0].'=', $raw);

        if (! in_array('reel_set_size=', $existingKeys, true)) {
            $out[] = "reel_set_size={$sets}";
        }

        if (! in_array('reel_set=', $existingKeys, true)) {
            $out[] = 'reel_set=0';
        }

        return $out;
    }

    private function defaultMsr(GameConfig $cfg): int
    {
        foreach ($cfg->paylineConfig()['raw'] as $line) {
            if (str_starts_with($line, 'msr=')) {
                return (int) substr($line, 4);
            }
        }

        return 17;
    }

    private function moneyFields(GameConfig $cfg, array $state): string
    {
        $symbol = $cfg->paylineConfig()['money_symbol'];
        if ($symbol === null || empty($state['Coins']['positions'])) {
            return '';
        }

        $values = array_combine($state['Coins']['positions'], $state['Coins']['values']);

        $mo = [];
        $moT = [];
        foreach ($state['SlotArea'] as $i => $sym) {
            if ($sym === $symbol) {
                $mo[] = $values[$i] ?? 0;
                $moT[] = 'v';
            } else {
                $mo[] = 0;
                $moT[] = 'r';
            }
        }

        return '&mo='.implode(',', $mo).'&mo_t='.implode(',', $moT);
    }

    private function buildQuery(array $params, bool $leadingAmp = false): string
    {
        $q = implode('&', array_map(
            fn ($k, $v) => "{$k}=".(is_float($v) ? number_format($v, 2, '.', '') : $v),
            array_keys($params), $params,
        ));

        return $leadingAmp ? '&'.$q : $q;
    }

    /** @param  list<array{symbol:int,count:int,pay:float,positions:list<int>,line:int}>  $lines */
    private function positions(array $lines): string
    {
        $out = '';
        foreach ($lines as $i => $line) {
            $out .= '&l'.$i.'='.$line['line'].'~'.number_format($line['pay'], 2, '.', '').'~'.implode('~', $line['positions']);
        }

        return $out;
    }
}
