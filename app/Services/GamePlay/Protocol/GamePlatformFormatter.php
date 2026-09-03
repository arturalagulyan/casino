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

        // The "fake" blur reels the client scrolls behind a spin. The per-game
        // engines (BaseSlot) only have a static texture for the low plain
        // symbols and crash (`Cannot read '_frame' of undefined`) on anything
        // higher — legacy hand-authored these plain-only, so we clamp to the
        // same safe range.
        $max = $this->safeMax($cfg);
        $fake = [];
        foreach (array_values($cfg->reelStrips(false)) as $strip) {
            $out = [];
            foreach (array_slice(array_map('intval', $strip), 0, 20) as $sym) {
                $out[] = $sym >= 0 && $sym <= $max ? $sym : $sym % ($max + 1);
            }
            $fake[] = $out;
        }

        return [
            'paytableCoef' => $coef,
            'scatterCoef' => $scatterCoef ?: (object) [],
            'mainFakeReels' => $fake,
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
            $out[] = $this->fillerSymbol($cfg);
            for ($r = 0; $r < $rows; $r++) {
                $out[] = (int) ($board[$reel][$r] ?? 0);
            }
            $out[] = $this->fillerSymbol($cfg);
        }

        return $out;
    }

    /**
     * Plain paying symbols only — no wild / scatter / bonus. The per-game
     * engines (BaseSlot especially) have no static texture for the special
     * symbols and crash (`Cannot read '_frame' of undefined`) if one appears in
     * the reel window they draw on init.
     *
     * @return list<int>
     */
    private function plainSymbols(GameConfig $cfg): array
    {
        $special = array_filter([$cfg->wildSymbol(), ...$cfg->triggerSymbols()], fn ($s) => $s !== null);
        $plain = array_values(array_filter($cfg->symbols(), fn ($s) => ! in_array($s, $special, true)));

        return $plain !== [] ? $plain : [0];
    }

    /**
     * The always-safe-to-draw symbol range: 0..6 (legacy's `rand(0, 6)` for every
     * idle / filler cell — the low symbols are the plain card faces every game
     * has a static texture for; the high indices are wild / scatter / bonus /
     * special art the per-game engines only draw in specific animated contexts).
     */
    private function safeMax(GameConfig $cfg): int
    {
        return max(2, min(6, $cfg->symbolCount() - 1));
    }

    private function fillerSymbol(GameConfig $cfg): int
    {
        return random_int(0, $this->safeMax($cfg));
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
        if ($cfg && is_array($stored) && $stored !== []) {
            $board = [];
            foreach ($stored as $col) {
                $board[] = array_map('intval', (array) $col);
            }

            return $this->window($cfg, $this->sanitiseBoard($cfg, $board));
        }

        return $cfg ? $this->window($cfg, $this->idleBoard($cfg)) : array_map(fn () => random_int(0, 6), range(1, 25));
    }

    /**
     * A fresh idle board — every cell a low plain symbol (legacy Server.php's
     * `rand(0, 6)` fill for a session with no last event). Nothing here may carry
     * wild / scatter / bonus / special art: the per-game engines draw this array
     * on init and throw `Cannot read '_frame' of undefined` on any symbol they
     * have no static texture for.
     *
     * @return list<list<int>>
     */
    public function idleBoard(GameConfig $cfg): array
    {
        $max = $this->safeMax($cfg);
        $board = [];
        for ($reel = 0; $reel < $cfg->reelCount(); $reel++) {
            $col = [];
            for ($r = 0; $r < $cfg->rowCount(); $r++) {
                $col[] = random_int(0, $max);
            }
            $board[] = $col;
        }

        return $board;
    }

    /**
     * Swap any wild / scatter / bonus symbol on the board for a plain one — the
     * idle / recovery window the client draws on init can't carry them.
     *
     * @param  list<list<int>>  $board
     * @return list<list<int>>
     */
    private function sanitiseBoard(GameConfig $cfg, array $board): array
    {
        $special = array_filter([$cfg->wildSymbol(), ...$cfg->triggerSymbols()], fn ($s) => $s !== null);
        if ($special === []) {
            return $board;
        }
        $plain = $this->plainSymbols($cfg);

        foreach ($board as $reel => $col) {
            foreach ($col as $r => $sym) {
                if (in_array($sym, $special, true)) {
                    $board[$reel][$r] = $plain[($reel + $r) % count($plain)];
                }
            }
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
