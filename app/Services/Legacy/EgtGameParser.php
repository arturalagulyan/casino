<?php

namespace App\Services\Legacy;

use App\Enums\Volatility;
use App\Services\GamePlay\GameConfig;
use Illuminate\Support\Str;

/**
 * Turns one legacy EGT "GamePlatform" game package
 * (`games-backend/<Code>/{SlotSettings.php,Server.php,reels.txt,GameReel.php}`)
 * into the DB-driven engine spec our {@see GameConfig}
 * reads.
 *
 * The legacy EGT games keep every number in code:
 *  - `SlotSettings.php` — the Paytable (`$this->Paytable['SYM_N'] = [...]`,
 *    index = match count), the feature flags (`slotBonus`, `slotGamble`,
 *    `slotFreeCount`, `slotFreeMpl`, `slotWildMpl`, `GambleType`), the grid.
 *  - `Server.php`     — the wild / scatter symbol indices (`$wild = ['8']`,
 *    `$scatter = '9'`), the bet-per-line ladder (`$gameBets`), and the
 *    paylines (`$linesId[N] = [...]`, rows 1-indexed).
 *  - `reels.txt`      — the reel strips (`reelStripN=` + `reelStripBonusN=`).
 *
 * Anything genuinely absent falls back to a sane EGT default; the win-chance
 * tables (`lines_percent_config_*`) come from the legacy DB, not here.
 */
class EgtGameParser
{
    private string $settings;

    private string $server;

    private string $reelsTxt;

    /** @var list<string> */
    public array $warnings = [];

    /** Game code (e.g. `BurningHot20EGT`), kept for diagnostics / callers. */
    public readonly string $code;

    private function __construct(private readonly string $dir, string $code)
    {
        $this->code = $code;
        $this->settings = $this->read('SlotSettings.php');
        $this->server = $this->read('Server.php');
        $this->reelsTxt = $this->read('reels.txt');
    }

    public static function fromDir(string $dir, string $code): ?self
    {
        return is_dir($dir) ? new self(rtrim(str_replace('\\', '/', $dir), '/'), $code) : null;
    }

    /** True when this looks like a line/dice slot we can port (not poker/keno/roulette). */
    public function isLineSlot(): bool
    {
        return $this->settings !== ''
            && str_contains($this->server, '$linesId[')
            && str_contains($this->server, '$scatter')
            && str_contains($this->settings, "Paytable['SYM_");
    }

    /**
     * The full `game_templates` attribute set (minus identity columns the
     * command owns: code / title / poster_path / layout).
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        $paytable = $this->paytable();
        $strips = $this->reelStrips();
        $reelCount = $this->reelCount($strips);
        $paylines = $this->paylines();
        $wild = $this->wildSymbol();
        $scatter = $this->scatterSymbol();
        $symbolCount = count($paytable) ?: 10;

        $hasBonusStrips = collect($strips)->keys()->contains(fn ($k) => Str::startsWith($k, 'reelStripBonus'));
        $slotBonus = $this->boolProp('slotBonus');
        $hasFreeSpins = $hasBonusStrips || $slotBonus;
        $freeCount = $this->intProp('slotFreeCount') ?? 10;

        return [
            'reel_count' => $reelCount,
            'row_count' => $this->rowCount($paylines),
            'symbol_count' => $symbolCount,
            'symbols' => range(0, $symbolCount - 1),
            'wild_symbol' => $wild,
            'scatter_symbol' => $scatter,
            'bonus_symbol' => null,
            'wild_multiplier' => $this->intProp('slotWildMpl') ?: 1,
            'min_match' => $this->minMatch($paytable),

            'has_bonus' => $slotBonus,
            'bonus_type' => $this->intProp('slotBonusType') ?? 1,
            'scatter_type' => $this->intProp('slotScatterType') ?? 0,
            'has_free_spins' => $hasFreeSpins,
            'free_spins_count' => $hasFreeSpins ? max(5, $freeCount) : $freeCount,
            'free_spins_table' => null,
            'free_spins_multiplier' => $this->intProp('slotFreeMpl') ?: 1,

            'has_gamble' => $this->boolProp('slotGamble', true),
            'gamble_type' => $this->intProp('GambleType') ?: 1,
            'gamble_win_chance' => 4,

            'split_screen' => $this->boolProp('splitScreen'),
            'volatility' => Volatility::High,
            'rtp_control_window' => 200,

            'paytable' => $paytable,
            'reel_strips' => $strips,
            'paylines' => $paylines,

            'bonus_config' => $this->bonusConfig($scatter, $hasFreeSpins),
            'default_bet_options' => $this->gameBets(),
            'default_denomination' => 1,
        ];
    }

    // ---- paytable -------------------------------------------------

    /** @return array<int, list<int>> symbol index => payout per match count (index = count) */
    private function paytable(): array
    {
        preg_match_all(
            "/Paytable\\['SYM_(\\d+)'\\]\\s*=\\s*\\[([\\s\\S]*?)\\]/",
            $this->settings,
            $m,
            PREG_SET_ORDER,
        );

        $out = [];
        foreach ($m as [, $sym, $body]) {
            $row = array_values(array_map(
                'intval',
                array_filter(array_map('trim', explode(',', $body)), fn ($v) => $v !== '' && is_numeric($v)),
            ));
            if ($row !== []) {
                $out[(int) $sym] = $row;
            }
        }
        ksort($out);

        if ($out === []) {
            $this->warnings[] = 'no paytable parsed';
        }

        return $out;
    }

    /** Smallest paying run across all symbols (EGT "Action Money" pays 2, most pay 3). */
    private function minMatch(array $paytable): int
    {
        $min = 5;
        foreach ($paytable as $row) {
            foreach ($row as $count => $pay) {
                if ($pay > 0) {
                    $min = min($min, max(2, (int) $count));
                    break;
                }
            }
        }

        return max(2, min($min, 3));
    }

    // ---- reels --------------------------------------------------

    /** @return array<string, list<int>> reelStrip{N} / reelStripBonus{N} => symbol list */
    private function reelStrips(): array
    {
        $out = [];
        foreach (preg_split('/\R/', $this->reelsTxt) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$name, $body] = array_map('trim', explode('=', $line, 2));
            if (! preg_match('/^reelStrip(Bonus)?[1-6]$/', $name)) {
                continue;
            }
            $strip = array_values(array_map(
                'intval',
                array_filter(array_map('trim', explode(',', $body)), fn ($v) => $v !== '' && is_numeric($v)),
            ));
            if ($strip !== []) {
                $out[$name] = $strip;
            }
        }

        if ($out === []) {
            $this->warnings[] = 'no reel strips parsed';
        }

        return $out;
    }

    /** @param array<string, list<int>> $strips */
    private function reelCount(array $strips): int
    {
        $main = collect($strips)->keys()
            ->filter(fn ($k) => preg_match('/^reelStrip[1-6]$/', $k))
            ->count();

        return $main ?: 5;
    }

    /**
     * Visible rows per reel. The EGT "40 lines" / "100 lines" games are 5×4 —
     * detectable from a payline that reaches the 4th row (`$linesId` value 4,
     * 0-indexed 3). `slotReelsConfig`'s 3rd column is NOT the row count (it's 3
     * even on the 4-row games), so paylines are the reliable source.
     *
     * @param  list<list<int>>|null  $paylines  already 0-indexed
     */
    private function rowCount(?array $paylines): int
    {
        $max = 0;
        foreach ($paylines ?? [] as $line) {
            $max = max($max, ...array_map('intval', $line));
        }

        return max(3, $max + 1);
    }

    // ---- symbols -----------------------------------------------

    private function wildSymbol(): ?int
    {
        if (preg_match("/\\\$wild\s*=\s*\[\s*'?(\d+)'?/", $this->server, $m)) {
            return (int) $m[1];
        }
        $this->warnings[] = 'no $wild in Server.php';

        return null;
    }

    private function scatterSymbol(): ?int
    {
        if (preg_match("/\\\$scatter\s*=\s*'?(\d+)'?/", $this->server, $m)) {
            return (int) $m[1];
        }
        $this->warnings[] = 'no $scatter in Server.php';

        return null;
    }

    // ---- paylines --------------------------------------------

    /** `$linesId[N] = [ 2, 2, 2, 2, 2 ];` (rows 1-indexed) → 0-indexed row per reel. */
    private function paylines(): ?array
    {
        preg_match_all('/\$linesId\[\s*\d+\s*\]\s*=\s*\[([0-9,\s]+)\]/', $this->server, $m);

        $lines = [];
        foreach ($m[1] as $body) {
            $row = array_values(array_map(
                fn ($v) => max(0, (int) trim($v) - 1),
                array_filter(explode(',', $body), fn ($v) => trim($v) !== ''),
            ));
            if (count($row) >= 3) {
                $lines[] = $row;
            }
        }

        if ($lines === []) {
            $this->warnings[] = 'no $linesId parsed';

            return null;
        }

        return $lines;
    }

    // ---- bets -----------------------------------------------

    /** @return list<float> the bet-per-line ladder ($gameBets in Server.php) */
    private function gameBets(): array
    {
        if (preg_match('/\$gameBets\s*=\s*\[([\s\S]*?)\]/', $this->server, $m)) {
            $bets = array_values(array_filter(array_map(
                'floatval',
                array_filter(array_map('trim', explode(',', $m[1])), fn ($v) => $v !== '' && is_numeric($v)),
            ), fn ($v) => $v > 0));
            if ($bets !== []) {
                return $bets;
            }
        }
        $this->warnings[] = 'no $gameBets — default [1,2,5,10,20]';

        return [1, 2, 5, 10, 20];
    }

    // ---- bonus config -------------------------------------

    /**
     * The generic EGT feature wiring: the scatter grants free spins, gamble is
     * red/black × 5. Games with a richer feature (Action Money's bank bonus)
     * get a per-code override in the import command.
     *
     * @return array<string, mixed>
     */
    private function bonusConfig(?int $scatter, bool $hasFreeSpins): array
    {
        $cfg = ['gamble' => ['type' => 'red_black', 'steps' => 5]];

        if ($scatter !== null && $hasFreeSpins) {
            $cfg['triggers'] = [(string) $scatter => ['flow' => 'free_spins', 'min' => 3]];
        }

        return $cfg;
    }

    // ---- helpers ----------------------------------------

    private function intProp(string $prop): ?int
    {
        return preg_match("/->{$prop}\s*=\s*(\d+)\s*;/", $this->settings, $m) ? (int) $m[1] : null;
    }

    private function boolProp(string $prop, bool $default = false): bool
    {
        if (preg_match("/->{$prop}\s*=\s*(true|false|\d+)\s*;/", $this->settings, $m)) {
            return $m[1] === 'true' || (is_numeric($m[1]) && (int) $m[1] > 0);
        }

        return $default;
    }

    private function read(string $file): string
    {
        $path = $this->dir.'/'.$file;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
