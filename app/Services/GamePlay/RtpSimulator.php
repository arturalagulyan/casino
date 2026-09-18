<?php

namespace App\Services\GamePlay;

use App\Enums\UserStatus;
use App\Filament\Actions\SimulateRtpAction;
use App\Models\Game;
use App\Models\Shop;
use App\Models\User;
use App\Services\Banker;
use App\Services\GamePlay\Engine\AbstractSlotServer;
use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\Ledger;

/**
 * Headless "spin this game N times and report RTP" tool for the admin panel —
 * {@see SimulateRtpAction}. Runs entirely off the books:
 * it plays as a throwaway `__rtp_sim` shop player exactly like {@see DemoLauncher}
 * (GameContext::$demo isolation — no Ledger, bank, jackpot or game_rounds writes),
 * just headless (no HTTP/WS wire, no front-end bundle needed) and driven straight
 * through {@see GameRegistry}/{@see AbstractSlotServer::bet()}.
 *
 * Every game — whatever wire protocol actually serves its live traffic (EGT
 * GamePlatform, Amatic, Novomatic slotEvent, plain HTTP…) — settles its spins
 * through the same GameConfig-driven {@see SlotEngine},
 * and GameRegistry has no per-template overrides registered anywhere in this
 * codebase, so it always resolves to that one shared engine. Running the
 * simulation through the generic command loop is therefore a faithful,
 * protocol-agnostic read on the game's real paytable/RTP math — it does not
 * need to reproduce any protocol's own wire framing.
 */
class RtpSimulator
{
    /** Hard ceiling so a mistaken admin input can't hang a web request for minutes. */
    public const int MAX_SPINS = 20000;

    /** Reserved per-shop username for the simulation account — separate from `__demo`. */
    private const string USERNAME = '__rtp_sim';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Banker $banker,
        private readonly GameRegistry $registry,
    ) {}

    /**
     * @return array{spins: int, lines: int, betline: float, stake_per_spin: float,
     *     currency: string, total_in: float, total_out: float, net: float, rtp: float,
     *     target_rtp: float, hit_rate: float, biggest_win: float, elapsed_seconds: float,
     *     game_name: string, shop_name: string,
     *     total_game_in: float, total_jackpot_in: float, total_profit: float,
     *     rows: list<array{spin: int, bet: float, win: float, net: float,
     *         game_in: float, jackpot_in: float, profit: float, balance_after: float}>}
     */
    public function run(Game $game, int $spins, float $betline, ?int $lines = null): array
    {
        $spins = max(1, min(self::MAX_SPINS, $spins));

        $game->loadMissing('shop', 'template', 'jackpot');
        $player = $this->player($game->shop);

        // Fund the throwaway wallet BEFORE constructing the context — GameContext
        // caches its Wallet on first read, so funding it after would leave the
        // context holding a stale (pre-funded) balance for the whole run. A flat,
        // oversized bankroll sidesteps needing to know the per-spin stake yet.
        $player->wallet()->update([
            'currency' => $player->currency ?? $game->shop->currency,
            'balance' => 1_000_000_000.0,
        ]);

        // persistDemoState: false — thousands of spins would otherwise mean
        // thousands of wallet/session UPDATE queries; this account is throwaway.
        $context = new GameContext($player, $game, $this->ledger, $this->banker, persistDemoState: false);

        $lines = $lines !== null && $lines > 0 ? $lines : $context->config()->lineCount();
        $stakePerSpin = round($lines * $betline * $context->denomination(), 4);

        $server = $this->registry->for($game);

        set_time_limit(120);

        $totalIn = 0.0;
        $totalOut = 0.0;
        $totalGameIn = 0.0;
        $totalJackpotIn = 0.0;
        $totalProfit = 0.0;
        $wins = 0;
        $biggestWin = 0.0;
        $rows = [];
        $start = microtime(true);

        for ($i = 0; $i < $spins; $i++) {
            $result = $server->handle($context, ['command' => 'bet', 'lines' => $lines, 'bet' => $betline]);

            $bet = (float) ($result['bet'] ?? 0);
            $win = (float) ($result['win'] ?? 0);
            $split = $context->previewSplit($bet);

            $totalIn += $bet;
            $totalOut += $win;
            $totalGameIn += $split['bank'];
            $totalJackpotIn += $split['jackpot'];
            $totalProfit += $split['profit'];

            if ($win > 0) {
                $wins++;
                $biggestWin = max($biggestWin, $win);
            }

            $rows[] = [
                'spin' => $i + 1,
                'bet' => round($bet, 4),
                'win' => round($win, 4),
                'net' => round($win - $bet, 4),
                'game_in' => $split['bank'],
                'jackpot_in' => $split['jackpot'],
                'profit' => $split['profit'],
                'balance_after' => round((float) ($result['balance'] ?? $context->balance()), 4),
            ];
        }

        return [
            'spins' => $spins,
            'lines' => $lines,
            'betline' => $betline,
            'stake_per_spin' => $stakePerSpin,
            'currency' => $context->currency->value,
            'game_name' => $game->title ?: $game->template->title,
            'shop_name' => $game->shop->name,
            'total_in' => round($totalIn, 2),
            'total_out' => round($totalOut, 2),
            'net' => round($totalIn - $totalOut, 2),
            'rtp' => $totalIn > 0 ? round($totalOut / $totalIn * 100, 2) : 0.0,
            'target_rtp' => round($context->rtpTarget(), 2),
            'hit_rate' => round($wins / $spins * 100, 2),
            'biggest_win' => round($biggestWin, 2),
            'total_game_in' => round($totalGameIn, 2),
            'total_jackpot_in' => round($totalJackpotIn, 2),
            'total_profit' => round($totalProfit, 2),
            'elapsed_seconds' => round(microtime(true) - $start, 2),
            'rows' => $rows,
        ];
    }

    /** Get (or create) the shop's dedicated RTP-simulation player. */
    private function player(Shop $shop): User
    {
        /** @var User $user */
        $user = User::withTrashed()->firstOrNew([
            'shop_id' => $shop->id,
            'username' => self::USERNAME,
        ]);

        $user->fill([
            'first_name' => 'RTP',
            'last_name' => 'Simulator',
            'currency' => $shop->currency,
            'status' => UserStatus::Active,
            'free_demo' => true,
        ]);

        if (! $user->exists) {
            $user->password = bcrypt(str()->random(40));
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $user->save();

        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }

        return $user->refresh();
    }
}
