<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\SpinResult;

/**
 * Formats the generic engine output into the shapes the EGT GamePlatform client
 * expects — reel windows, win lines, the paytable/scatter coefficient blocks.
 * EGT-family-wide; reads everything it needs from {@see GameConfig}.
 */
class GamePlatformFormatter
{
    /** The `bet` response `complex` block. */
    public function betComplex(GameConfig $cfg, SpinResult $result, array $state, array $extra): array
    {
        return array_merge([
            'gambles' => $cfg->gambleConfig()['steps'],
            'reels' => $this->window($cfg, $result->reels),
            'lines' => $this->winLines($result->lines),
            'scatters' => $this->scatterList($result),
            'freespinScatters' => $cfg->scatterSymbol() !== null ? [$cfg->scatterSymbol()] : [],
            'choices' => 0,
            'expand' => [],
            'specialExpand' => [],
            'multiplier' => (int) ($state['multiplier'] ?? 1),
            'wildcard' => (int) ($state['extra_wild'] ?? -1),
            'freespins' => (int) ($state['free_spins_left'] ?? 0),
            'jackpot' => false,
            'gameCommand' => 'bet',
        ], $extra);
    }

    /** paytableCoef + scatterCoef + mainFakeReels for the settings response. */
    public function paytableCoef(GameConfig $cfg): array
    {
        $paytable = $cfg->paytable();
        $triggers = $cfg->triggerSymbols();

        $coef = [];
        foreach ($paytable as $sym => $row) {
            $nonZero = array_values(array_filter(array_map('intval', $row), fn ($v) => $v > 0));
            $coef[(string) $sym] = ['coef' => $nonZero ?: [0], 'multiplier' => 1];
        }

        $scatterCoef = [];
        foreach ($triggers as $sym) {
            $row = $paytable[$sym] ?? [];
            foreach ([3, 4, 5] as $n) {
                $v = (int) ($row[$n] ?? $row[$n - 1] ?? 0);
                $scatterCoef[(string) $n]['withoutBonus'][] = $v;
                $scatterCoef[(string) $n]['bonus'][] = $v;
            }
        }

        return [
            'paytableCoef' => $coef,
            'scatterCoef' => $scatterCoef ?: (object) [],
            'mainFakeReels' => array_map(
                fn ($strip) => array_slice(array_map('intval', $strip), 0, 20),
                array_values($cfg->reelStrips(false)),
            ),
        ];
    }

    /**
     * The 5×3 grid as the client's flat reel window: [filler, r0, r1, r2, filler]
     * per reel.
     *
     * @return list<int>
     */
    public function window(GameConfig $cfg, array $board): array
    {
        $rows = $cfg->rowCount();
        $out = [];
        for ($reel = 0; $reel < $cfg->reelCount(); $reel++) {
            $out[] = random_int(0, 6);
            for ($r = 0; $r < $rows; $r++) {
                $out[] = (int) ($board[$reel][$r] ?? 0);
            }
            $out[] = random_int(0, 6);
        }

        return $out;
    }

    /** `reelsSymbols`-style map for state recovery. */
    public function reelsSymbols(GameConfig $cfg, array $board): array
    {
        $out = [];
        for ($reel = 0; $reel < $cfg->reelCount(); $reel++) {
            $col = [];
            for ($r = 0; $r < $cfg->rowCount(); $r++) {
                $col[] = (int) ($board[$reel][$r] ?? 0);
            }
            $col[] = '';
            $out['reel'.($reel + 1)] = $col;
        }

        return $out;
    }

    /**
     * Reel window for the `subscribe` response. Reuses a stored spin board when
     * there is one, otherwise samples a fresh idle board from the real strips.
     * Shape matches {@see window()} exactly — [filler, ...rows, filler] per reel,
     * `rowCount` rows — which the per-game engines (BaseSlot especially) require
     * on init; a mis-sized / flat array crashes their reel controller.
     */
    public function recoverReels(?array $payload, ?GameConfig $cfg = null): array
    {
        $stored = $payload['reels'] ?? null;
        if (is_array($stored) && $stored !== []) {
            $out = [];
            foreach ($stored as $col) {
                $out[] = random_int(0, 6);
                foreach ((array) $col as $sym) {
                    $out[] = (int) $sym;
                }
                $out[] = random_int(0, 6);
            }

            return $out;
        }

        return $cfg ? $this->window($cfg, $this->idleBoard($cfg)) : array_map(fn () => random_int(0, 6), range(1, 25));
    }

    /**
     * A plausible idle board: each reel strip cut at a random offset.
     *
     * @return list<list<int>>
     */
    public function idleBoard(GameConfig $cfg): array
    {
        $strips = array_values($cfg->reelStrips(false));
        $rows = $cfg->rowCount();
        $board = [];
        for ($reel = 0; $reel < $cfg->reelCount(); $reel++) {
            $strip = array_map('intval', $strips[$reel] ?? $strips[0] ?? [0]);
            $strip = $strip !== [] ? $strip : [0];
            $at = random_int(0, count($strip) - 1);
            $col = [];
            for ($r = 0; $r < $rows; $r++) {
                $col[] = $strip[($at + $r) % count($strip)];
            }
            $board[] = $col;
        }

        return $board;
    }

    /** @return list<array<string, mixed>> */
    public function winLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (($line['line'] ?? 0) < 0) {
                continue; // scatter award — reported separately
            }
            $out[] = [
                'line' => (int) $line['line'],
                'winAmount' => (int) round(($line['amount'] ?? 0) * 100),
                'cells' => $line['cells'] ?? [],
                'freespins' => 0,
                'card' => (int) ($line['symbol'] ?? 0),
            ];
        }

        return $out;
    }

    private function scatterList(SpinResult $result): array
    {
        $cells = $result->extra['scatter_cells'] ?? [];
        $out = [];
        foreach (($result->extra['scatters'] ?? []) as $sym => $count) {
            if ($count < 3) {
                continue;
            }
            $flat = [];
            foreach ($cells[$sym] ?? [] as [$reel, $row]) {
                $flat[] = $reel;
                $flat[] = $row;
            }
            $out[] = ['scatterName' => (int) $sym, 'cells' => $flat, 'winAmount' => 0, 'freespins' => 0];
        }

        return $out;
    }

    public function emptyJackpotState(): array
    {
        $out = ['levelI' => 0, 'levelII' => 0, 'levelIII' => 0, 'levelIV' => 0];
        foreach (['I', 'II', 'III', 'IV'] as $l) {
            $out["winsLevel{$l}"] = 0;
            $out["largestWinLevel{$l}"] = 0;
            $out["largestWinDateLevel{$l}"] = '';
            $out["largestWinUserLevel{$l}"] = '';
            $out["lastWinLevel{$l}"] = 0;
            $out["lastWinDateLevel{$l}"] = '';
            $out["lastWinUserLevel{$l}"] = '';
        }

        return $out;
    }
}
