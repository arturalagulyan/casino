<?php

namespace App\Services\GamePlay\Protocol;

use App\Models\GameLog;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use App\Services\Legacy\LegacyGameReader;

/**
 * Formats the generic {@see SlotEngine} output into
 * the shapes the legacy VanguardLTE `slotEvent` front-end (Novomatic / Greentube
 * `js/core.js`) reads: the whole `SlotSettings` object for `getSettings`, and the
 * `{reelsSymbols, winLines, bonusInfo, …}` spin body — all keyed by symbol
 * **name** (`P_1`, `SCAT`, `A`), which the DB import replaced with integer
 * indices. The names + cosmetic config come from {@see LegacyGameReader::slotSettings}.
 */
class SlotEventFormatter
{
    public function __construct(private readonly LegacyGameReader $legacy) {}

    /** Index → symbol name for a game (falls back to "SYM_<n>"). */
    private function names(string $code, GameConfig $cfg): array
    {
        $names = $this->legacy->slotSettings($code)['symbol_names'] ?? [];
        $out = [];
        foreach ($cfg->symbols() as $i => $_) {
            $out[$i] = (string) ($names[$i] ?? 'SYM_'.$i);
        }

        return $out;
    }

    // ---- getSettings -------------------------------------------------

    /** The `serverResponse` object for `{"responseEvent":"getSettings"}`. */
    public function settings(GameContext $ctx, array $state): array
    {
        $cfg = $ctx->config();
        $code = $ctx->game->template->code;
        $raw = $this->legacy->slotSettings($code);
        $names = $this->names($code, $cfg);
        $denom = $cfg->denomination();

        $out = [
            'slotId' => $code,
            'slotDBId' => (string) $ctx->game->id,
            'Balance' => $this->credits($ctx->balance(), $denom),
            'CurrentDenom' => $denom,
            'slotCurrency' => $ctx->currency->value,
            'Bet' => array_map(fn ($b) => $this->num($b), $ctx->betOptions()),
            'Line' => $raw['line'] ?? array_map('strval', range(1, max(1, $cfg->lineCount()))),
            'gameLine' => $raw['game_line'] ?? array_map('strval', range(1, max(1, $cfg->lineCount()))),
            'Paytable' => $this->paytableByName($cfg, $names),
            'SymbolGame' => array_values($names),
            'slotReelsConfig' => $raw['slot_reels_config'] ?? $this->defaultReelsConfig($cfg),
            'keyController' => $raw['key_controller'] ?? (object) [],
            'slotWildMpl' => $cfg->wildMultiplier() ?: ($raw['slot_wild_mpl'] ?? 1),
            'slotFreeMpl' => $cfg->freeSpinsMultiplier() ?: ($raw['slot_free_mpl'] ?? 1),
            'slotFreeCount' => $cfg->freeSpinsCount() ?: ($raw['slot_free_count'] ?? 0),
            'slotScatterFreeCount' => $cfg->template->free_spins_table
                ?: ($raw['slot_scatter_free_count'] ?? [0, 0, 0, 10, 15, 20]),
            'GambleType' => $cfg->gambleType() ?: ($raw['gamble_type'] ?? 1),
            'slotGamble' => $cfg->hasGamble(),
            'slotBonus' => $cfg->hasFreeSpins() || $cfg->hasBonus(),
            'slotBonusType' => (int) $cfg->template->bonus_type ?: ($raw['slot_bonus_type'] ?? 1),
            'slotScatterType' => (int) $cfg->template->scatter_type ?: ($raw['slot_scatter_type'] ?? 0),
            'slotViewState' => $ctx->game->view_state?->value ?: ($raw['slot_view_state'] ?? 'Normal'),
            'splitScreen' => (bool) ($cfg->template->split_screen ?? $raw['split_screen'] ?? false),
            'slotExitUrl' => $raw['slot_exit_url'] ?? '/',
            'numFloat' => $raw['num_float'] ?? 0,
            'scaleMode' => $raw['scale_mode'] ?? 0,
            'slotFastStop' => 1,
            'hideButtons' => [],
            'isBonusStart' => (int) ($state['free_spins_left'] ?? 0) > 0,
            'slotSounds' => $this->sounds($code),
            'Jackpots' => $this->jackpots($ctx),
            'lastEvent' => $this->lastEvent($ctx),
        ];

        // reelStrip1..N and reelStripBonus1..N as NAME lists (client draws from these)
        foreach ([false, true] as $bonus) {
            $prefix = $bonus ? 'reelStripBonus' : 'reelStrip';
            foreach ($cfg->reelStrips($bonus) as $i => $strip) {
                $out[$prefix.($i + 1)] = array_map(fn ($s) => $names[$s] ?? (string) $s, $strip);
            }
        }

        return $out;
    }

    /** @param array<int,string> $names */
    private function paytableByName(GameConfig $cfg, array $names): array
    {
        $out = [];
        foreach ($cfg->paytable() as $idx => $row) {
            $out[$names[$idx] ?? (string) $idx] = array_map(fn ($v) => $this->num($v), $row);
        }

        return $out;
    }

    private function defaultReelsConfig(GameConfig $cfg): array
    {
        $out = [];
        for ($r = 0; $r < $cfg->reelCount(); $r++) {
            $out[] = [97 + $r * 114, 114, $cfg->rowCount()];
        }

        return $out;
    }

    private function sounds(string $code): array
    {
        $dir = $this->legacy->bundleDir($code).'/source/SOUND';

        return is_dir($dir)
            ? array_values(array_filter(scandir($dir) ?: [], fn ($f) => ! str_starts_with($f, '.')))
            : [];
    }

    private function jackpots(GameContext $ctx): array
    {
        $out = ['jack1' => 0, 'jack2' => 0, 'jack3' => 0];
        foreach (array_values($ctx->jackpots()->all()) as $i => $j) {
            if ($i < 3) {
                $out['jack'.($i + 1)] = $this->num((float) $j->balance);
            }
        }

        return $out;
    }

    /** Last non-gamble spin response (legacy GetHistory), or the string "NULL". */
    private function lastEvent(GameContext $ctx): string
    {
        $payload = GameLog::query()
            ->where('game_id', $ctx->game->id)
            ->where('user_id', $ctx->user->id)
            ->latest('id')
            ->limit(10)
            ->pluck('payload');

        foreach ($payload as $raw) {
            $decoded = json_decode((string) $raw, true);
            if (($decoded['responseEvent'] ?? null) === 'spin') {
                return (string) $raw;
            }
        }

        return 'NULL';
    }

    // ---- spin -------------------------------------------------------

    /**
     * The `serverResponse` for `{"responseEvent":"spin"}`.
     *
     * @param  array<string,mixed>  $state  free-spin bookkeeping
     */
    public function spin(GameContext $ctx, SpinResult $r, array $state, string $event): array
    {
        $cfg = $ctx->config();
        $names = $this->names($ctx->game->template->code, $cfg);
        $denom = $cfg->denomination();
        $isFree = $event === 'freespin';

        $displayBalance = $this->credits((float) ($state['frozen_balance'] ?? $ctx->balance()), $denom);
        $totalWin = $isFree
            ? $this->credits((float) ($state['bonus_win'] ?? $r->win), $denom)
            : $this->credits($r->win, $denom);

        [$winLines, $lastStep] = $this->winLines($r, $cfg, $names, $denom, (float) ($state['bonus_win'] ?? 0));

        return [
            'slotLines' => (int) ($state['last_lines'] ?? $cfg->lineCount()),
            'slotBet' => $this->num((float) ($state['last_bet'] ?? 0)),
            'totalFreeGames' => (int) ($state['free_spins_total'] ?? 0),
            'currentFreeGames' => (int) ($state['free_spins_used'] ?? 0),
            'Balance' => $displayBalance,
            'afterBalance' => $this->credits($ctx->balance(), $denom),
            'totalWin' => $totalWin,
            'winLines' => $winLines,
            'bonusInfo' => $this->bonusInfo($r, $cfg, $names, $denom),
            'Jackpots' => $this->jackpots($ctx),
            'reelsSymbols' => $this->reelsSymbols($r->reels, $cfg, $names),
        ];
    }

    /** {reel1:["P_1","K","10",""], …, rp:[pos,…]} */
    private function reelsSymbols(array $board, GameConfig $cfg, array $names): array
    {
        $out = [];
        $rp = [];
        for ($reel = 0; $reel < $cfg->reelCount(); $reel++) {
            $col = $board[$reel] ?? [];
            $row = [];
            for ($r = 0; $r < 4; $r++) {
                $row[] = $r < $cfg->rowCount() ? ($names[$col[$r] ?? 0] ?? '') : '';
            }
            $out['reel'.($reel + 1)] = $row;
            $rp[] = 0;
        }
        $out['rp'] = $rp;

        return $out;
    }

    /**
     * @param  array<int,string>  $names
     * @return array{0: list<array<string,mixed>>, 1: float} [winLines, running total]
     */
    private function winLines(SpinResult $r, GameConfig $cfg, array $names, float $denom, float $carry): array
    {
        $lines = [];
        $running = $carry;
        $paylines = $cfg->paylines();

        foreach ($r->lines as $w) {
            if (($w['line'] ?? 0) < 0) {
                continue;   // scatter — reported in bonusInfo
            }
            $win = $this->credits((float) $w['amount'], $denom);
            $running += $win;
            $rowByReel = $paylines[$w['line']] ?? [];
            $entry = [
                'Count' => (int) $w['count'],
                'Line' => (int) $w['line'],
                'Win' => $win,
                'stepWin' => $this->num($running),
            ];
            for ($reel = 0; $reel < $cfg->reelCount(); $reel++) {
                $entry['winReel'.($reel + 1)] = $reel < (int) $w['count']
                    ? [(int) ($rowByReel[$reel] ?? 0), $names[$r->reels[$reel][$rowByReel[$reel] % $cfg->rowCount()] ?? 0] ?? 'none']
                    : ['none', 'none'];
            }
            $lines[] = $entry;
        }

        return [$lines, $running];
    }

    /** @param array<int,string> $names */
    private function bonusInfo(SpinResult $r, GameConfig $cfg, array $names, float $denom): array
    {
        $scatter = $cfg->scatterSymbol();
        $cells = $r->extra['scatter_cells'][$scatter] ?? [];
        $count = count($cells);

        $info = [];
        foreach ($cells as [$reel, $row]) {
            $info['winReel'.($reel + 1)] = [(int) $row, $names[$scatter] ?? 'SCAT'];
        }

        $scatterWin = 0.0;
        foreach ($r->lines as $w) {
            if (($w['line'] ?? 0) < 0) {
                $scatterWin += (float) $w['amount'];
            }
        }

        $info['scattersType'] = $count >= 3 ? 'bonus' : ($scatterWin > 0 ? 'win' : '');
        $info['scattersWin'] = $this->credits($scatterWin, $denom);

        return $info;
    }

    // ---- gamble ---------------------------------------------------

    /**
     * @param  array{won:bool,before:float,after:float}  $g
     */
    public function gamble(GameContext $ctx, array $g, string $choice, float $denom): array
    {
        $red = in_array($choice, ['red', '1'], true);
        $suit = $g['won']
            ? ($red ? ['D', 'H'][random_int(0, 1)] : ['C', 'S'][random_int(0, 1)])
            : ($red ? ['C', 'S'][random_int(0, 1)] : ['D', 'H'][random_int(0, 1)]);

        return [
            'dealerCard' => $suit,
            'gambleState' => $g['won'] ? 'win' : 'lose',
            'totalWin' => $this->credits($g['after'], $denom),
            'afterBalance' => $this->credits($ctx->balance(), $denom),
            'Balance' => $this->credits($ctx->balance(), $denom),
        ];
    }

    // ---- helpers ------------------------------------------------

    private function credits(float $currency, float $denom): float
    {
        return $this->num($denom > 0 ? $currency / $denom : $currency);
    }

    /** Trim float noise; whole numbers stay ints so JSON matches legacy. */
    private function num(float|int|string $v): float|int
    {
        $f = round((float) $v, 4);

        return $f == (int) $f ? (int) $f : $f;
    }
}
