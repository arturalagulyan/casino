<?php

namespace App\Services;

use App\Enums\BankType;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Models\GameBank;
use App\Models\Jackpot;
use App\Models\Shop;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shop-bank liquidity: a spin's stake feeds a pool, wins drain it, and any pool
 * that grows past shops.player_limit has its surplus swept to house profit.
 *
 * ← legacy Lib\Banker + GameBank::boot + w_game_bank overflow handling. The RNG /
 * win decision belongs to the (not-yet-built) spin engine — this service is the
 * money side it will call once per settled round.
 */
class Banker
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Settle one round's money movement against a shop bank. `$split['bank']` is
     * the slice of the stake that feeds the pool (default 70% of the bet).
     *
     * @param  array{bank?: float, jackpot?: float, profit?: float}  $split
     * @return array{bank_after: float, swept: float}
     */
    public function settleRound(
        GameBank $bank,
        BankType $pool,
        float $bet,
        float $win,
        array $split = [],
        ?User $house = null,
    ): array {
        $toBank = $split['bank'] ?? round($bet * 0.70, 4);
        $column = $pool->column();

        return DB::transaction(function () use ($bank, $column, $pool, $toBank, $win, $house) {
            $bank = GameBank::whereKey($bank->getKey())->lockForUpdate()->first();

            $bank->increment($column, $toBank);   // stake feeds the pool
            $bank->decrement($column, $win);      // win drains it (may go negative, as legacy allows)

            $swept = $this->sweepOverflow($bank, $pool, $house);

            return ['bank_after' => (float) $bank->fresh()->{$column}, 'swept' => $swept];
        });
    }

    /**
     * If the pool sits above the shop's player_limit, move the surplus out to
     * profit (a game_bank debit). Returns the amount swept.
     */
    public function sweepOverflow(GameBank $bank, BankType $pool, ?User $house = null): float
    {
        $limit = (float) ($bank->shop?->player_limit ?? 0);

        if ($limit <= 0) {
            return 0.0;
        }

        $column = $pool->column();
        $current = (float) $bank->{$column};
        $surplus = round($current - $limit, 4);

        if ($surplus <= 0) {
            return 0.0;
        }

        $house ??= $bank->shop?->owner ?? User::whereHas('roles', fn ($q) => $q->where('slug', 'admin'))->first();

        if ($house) {
            $this->ledger->adjustBankPool($bank, $pool, $surplus, TxnDirection::Debit, $house, ['reason' => 'overflow_sweep']);
        } else {
            $bank->decrement($column, $surplus);
        }

        return $surplus;
    }

    /**
     * Accrue a jackpot from a stake slice (legacy w_jpg.percent contribution).
     * Returns the new pool balance.
     */
    public function contributeToJackpot(Jackpot $jackpot, float $stake): float
    {
        return DB::transaction(function () use ($jackpot, $stake) {
            $jackpot = Jackpot::whereKey($jackpot->getKey())->lockForUpdate()->first();

            if (! $jackpot->is_active) {
                return (float) $jackpot->balance;
            }

            $contribution = round($stake * (float) $jackpot->contribution_percent / 100, 6);
            $jackpot->increment('balance', $contribution);

            return (float) $jackpot->fresh()->balance;
        });
    }

    /**
     * Whether a jackpot is eligible to drop now (pool within its payout range).
     * The engine decides the random trigger; this is just the ceiling/floor check.
     */
    public function jackpotReady(Jackpot $jackpot): bool
    {
        $balance = (float) $jackpot->balance;

        return $jackpot->is_active
            && $balance >= (float) $jackpot->payout_min
            && ((float) $jackpot->payout_max === 0.0 || $balance <= (float) $jackpot->payout_max);
    }

    /** Total liquidity a shop is holding, per currency. */
    public function shopLiquidity(Shop $shop): array
    {
        return $shop->banks()
            ->get()
            ->mapWithKeys(fn (GameBank $b) => [
                ($b->currency?->value ?? 'EUR') => (float) $b->total(),
            ])
            ->all();
    }

    /** @return Collection<int,Transaction> the sweeps in a period */
    public function sweepsSince(\DateTimeInterface $since)
    {
        return Transaction::query()
            ->where('source', TxnSource::GameBank)
            ->where('direction', TxnDirection::Debit)
            ->where('created_at', '>=', $since)
            ->get();
    }
}
