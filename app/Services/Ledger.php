<?php

namespace App\Services;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Models\GameBank;
use App\Models\Jackpot;
use App\Models\JackpotWin;
use App\Models\Shop;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place balances move. Ports legacy VanguardLTE\User::addBalance() +
 * the VanguardLTE\Statistic::boot() accounting derivation (w_statistics_add).
 *
 * Every method: locks the affected rows, writes ONE transactions row with
 * balance_before + currency + the `accounting` json block, and returns it.
 */
class Ledger
{
    /**
     * Move a player's real balance. A cashier deposit (source=handpay) also
     * drains / refills the shop credit float, exactly like the legacy flow.
     */
    public function adjustPlayer(
        User $player,
        float $amount,
        TxnDirection $direction,
        User $actor,
        TxnSource $source = TxnSource::Handpay,
        array $context = [],
        ?string $title = null,
    ): Transaction {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($player, $amount, $direction, $actor, $source, $context, $title) {
            $wallet = $this->lockWallet($player);
            $signed = $direction === TxnDirection::Debit ? -$amount : $amount;

            if ($direction === TxnDirection::Debit && (float) $wallet->balance < $amount) {
                throw new RuntimeException("Not enough money in {$player->username}'s balance ({$wallet->balance}).");
            }

            $shop = null;

            if ($source === TxnSource::Handpay && $actor->hasRole('cashier') && $player->shop_id) {
                $shop = Shop::whereKey($player->shop_id)->lockForUpdate()->first();

                if ($shop && $direction === TxnDirection::Credit && (float) $shop->balance < $amount) {
                    throw new RuntimeException("Not enough credit in shop {$shop->name} ({$shop->balance}).");
                }
            }

            $txn = $this->write(
                user: $player,
                actor: $actor,
                shop: $shop ?? $player->shop,
                direction: $direction,
                source: $source,
                amount: $amount,
                balanceBefore: (float) $wallet->balance,
                currency: $wallet->currency ?? Currency::default(),
                context: $context,
                title: $title,
            );

            $wallet->increment('balance', $signed);
            $wallet->increment($direction === TxnDirection::Debit ? 'total_withdrawn' : 'total_deposited', $amount);
            $wallet->increment('wager_total', $amount);

            // Deposit pulls FROM the shop float, withdrawal returns TO it.
            $shop?->decrement('balance', $signed);

            return $txn;
        });
    }

    /**
     * Staff-to-staff credit transfer (admin→agent→distributor→manager).
     * The actor's own wallet funds it unless the actor is an admin (infinite).
     */
    public function adjustStaff(User $staff, float $amount, TxnDirection $direction, User $actor, array $context = []): Transaction
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($staff, $amount, $direction, $actor, $context) {
            $wallet = $this->lockWallet($staff);
            $signed = $direction === TxnDirection::Debit ? -$amount : $amount;

            if ($direction === TxnDirection::Debit && (float) $wallet->balance < $amount) {
                throw new RuntimeException("Not enough money in {$staff->username}'s balance ({$wallet->balance}).");
            }

            $actorWallet = null;

            if (! $actor->hasRole('admin') && $actor->isNot($staff)) {
                $actorWallet = $this->lockWallet($actor);

                if ($direction === TxnDirection::Credit && (float) $actorWallet->balance < $amount) {
                    throw new RuntimeException("Not enough credit in your balance ({$actorWallet->balance}).");
                }
            }

            $txn = $this->write(
                user: $staff,
                actor: $actor,
                shop: $staff->shop,
                direction: $direction,
                source: TxnSource::PlayerTransfer,
                amount: $amount,
                balanceBefore: (float) $wallet->balance,
                currency: $wallet->currency ?? Currency::default(),
                context: $context,
            );

            $wallet->increment('balance', $signed);
            $actorWallet?->decrement('balance', $signed);

            return $txn;
        });
    }

    /** Top up / draw down a shop's own credit float (legacy ShopController@balance). */
    public function adjustShopCredit(Shop $shop, float $amount, TxnDirection $direction, User $actor, array $context = []): Transaction
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($shop, $amount, $direction, $actor, $context) {
            $shop = Shop::whereKey($shop->getKey())->lockForUpdate()->first();
            $signed = $direction === TxnDirection::Debit ? -$amount : $amount;

            if ($direction === TxnDirection::Debit && (float) $shop->balance < $amount) {
                throw new RuntimeException("Not enough credit in shop {$shop->name} ({$shop->balance}).");
            }

            $txn = $this->write(
                user: $shop->owner ?? $actor,
                actor: $actor,
                shop: $shop,
                direction: $direction,
                source: TxnSource::ShopTransfer,
                amount: $amount,
                balanceBefore: (float) $shop->balance,
                currency: $shop->currency ?? Currency::default(),
                context: $context,
            );

            $shop->increment('balance', $signed);

            return $txn;
        });
    }

    /** Move money into/out of one liquidity pool of a shop bank (admin banks screen). */
    public function adjustBankPool(GameBank $bank, BankType $pool, float $amount, TxnDirection $direction, User $actor, array $context = []): Transaction
    {
        $this->assertPositive($amount);
        $column = $pool->column();

        return DB::transaction(function () use ($bank, $column, $pool, $amount, $direction, $actor, $context) {
            $bank = GameBank::whereKey($bank->getKey())->lockForUpdate()->first();
            $signed = $direction === TxnDirection::Debit ? -$amount : $amount;

            if ($direction === TxnDirection::Debit && (float) $bank->{$column} < $amount) {
                throw new RuntimeException("Not enough in the {$pool->value} pool ({$bank->{$column}}).");
            }

            $txn = $this->write(
                user: $bank->shop->owner ?? $actor,
                actor: $actor,
                shop: $bank->shop,
                direction: $direction,
                source: TxnSource::GameBank,
                amount: $amount,
                balanceBefore: (float) $bank->{$column},
                currency: $bank->currency ?? Currency::default(),
                context: $context + ['pool' => $pool->value],
                title: ucfirst($pool->value).' pool',
            );

            $bank->increment($column, $signed);

            return $txn;
        });
    }

    /** Set a jackpot's balance to an absolute figure, logging the delta (admin edit). */
    public function setJackpotBalance(Jackpot $jackpot, float $newBalance, User $actor): ?Transaction
    {
        if ($newBalance < 0) {
            throw new RuntimeException('Jackpot balance cannot be negative.');
        }

        return DB::transaction(function () use ($jackpot, $newBalance, $actor) {
            $jackpot = Jackpot::whereKey($jackpot->getKey())->lockForUpdate()->first();
            $before = (float) $jackpot->balance;
            $delta = round($newBalance - $before, 6);

            if ($delta === 0.0) {
                return null;
            }

            $txn = $this->write(
                user: $jackpot->shop?->owner ?? $actor,
                actor: $actor,
                shop: $jackpot->shop,
                direction: $delta > 0 ? TxnDirection::Credit : TxnDirection::Debit,
                source: TxnSource::Jackpot,
                amount: abs($delta),
                balanceBefore: $before,
                currency: $jackpot->shop?->currency ?? Currency::default(),
                context: [],
                title: $jackpot->name,
            );

            $jackpot->update(['balance' => $newBalance]);

            return $txn;
        });
    }

    /** Award the whole pot to a winner, reset it, and log it (legacy JPGController@immediately). */
    public function payoutJackpot(Jackpot $jackpot, ?User $winner, User $actor, array $context = []): Transaction
    {
        return DB::transaction(function () use ($jackpot, $winner, $actor, $context) {
            $jackpot = Jackpot::whereKey($jackpot->getKey())->lockForUpdate()->first();
            $winner ??= $jackpot->lastWinner;

            if (! $winner) {
                throw new RuntimeException('No winner to pay this jackpot to.');
            }

            $amount = (float) $jackpot->balance;

            if ($amount <= 0) {
                throw new RuntimeException('Jackpot balance is zero.');
            }

            $wallet = $this->lockWallet($winner);

            $txn = $this->write(
                user: $winner,
                actor: $actor,
                shop: $jackpot->shop,
                direction: TxnDirection::Credit,
                source: TxnSource::Jackpot,
                amount: $amount,
                balanceBefore: (float) $wallet->balance,
                currency: $wallet->currency ?? $jackpot->shop?->currency ?? Currency::default(),
                context: $context,
                title: "Jackpot: {$jackpot->name}",
            );

            $wallet->increment('balance', $amount);

            JackpotWin::create([
                'jackpot_id' => $jackpot->id,
                'user_id' => $winner->id,
                'shop_id' => $winner->shop_id ?? $jackpot->shop_id,
                'amount' => $amount,
                'balance_before' => $jackpot->balance,
                'won_at' => now(),
            ]);

            $jackpot->update([
                'balance' => 0,
                'last_winner_id' => $winner->id,
                'last_won_at' => now(),
                'last_won_amount' => $amount,
            ]);

            return $txn;
        });
    }

    // ---- internals ----------------------------------------------------

    private function write(
        User $user,
        User $actor,
        ?Shop $shop,
        TxnDirection $direction,
        TxnSource $source,
        float $amount,
        float $balanceBefore,
        Currency $currency,
        array $context,
        ?string $title = null,
    ): Transaction {
        return Transaction::create([
            'shop_id' => $shop?->getKey(),
            'user_id' => $user->getKey(),
            'counterparty_id' => $actor->getKey(),
            'direction' => $direction,
            'source' => $source,
            'currency' => $currency,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'title' => $title,
            'context' => $context ?: null,
            'accounting' => $this->buildAccounting($source, $direction, $actor, $amount) ?: null,
        ]);
    }

    /**
     * Hierarchy P&L block — a direct port of VanguardLTE\Statistic::boot().
     * Keyed on (source, direction, actor role); values are the moved amount.
     */
    public function buildAccounting(TxnSource $source, TxnDirection $direction, User $actor, float $amount): array
    {
        $add = $direction === TxnDirection::Credit;
        $data = [];

        $playerFacing = [
            TxnSource::Handpay, TxnSource::PlayerTransfer,
            TxnSource::Interkassa, TxnSource::Coinbase, TxnSource::BtcPayServer,
        ];

        $bonus = [
            TxnSource::HappyHour, TxnSource::Progress, TxnSource::Tournament, TxnSource::Refund,
            TxnSource::Invite, TxnSource::DailyEntry, TxnSource::WelcomeBonus,
            TxnSource::SmsBonus, TxnSource::WheelFortune,
        ];

        if (in_array($source, $playerFacing, true)) {
            if ($actor->hasRole('admin')) {
                $data[$add ? 'agent_in' : 'agent_out'] = $amount;
            } elseif ($actor->hasRole('agent')) {
                $data += $add
                    ? ['agent_out' => $amount, 'distributor_in' => $amount]
                    : ['distributor_out' => $amount, 'agent_in' => $amount];
            } elseif ($actor->hasRole('cashier')) {
                $data += $add
                    ? ['credit_out' => $amount, 'money_in' => $amount]
                    : ['money_out' => $amount, 'credit_in' => $amount];
            }
        } elseif ($source === TxnSource::ShopTransfer) {
            $data += $add
                ? ['distributor_out' => $amount, 'credit_in' => $amount]
                : ['distributor_in' => $amount, 'credit_out' => $amount];
        } elseif ($source === TxnSource::Pincode) {
            $data += $add
                ? ['credit_out' => $amount, 'money_in' => $amount]
                : ['money_out' => $amount, 'credit_in' => $amount];
        } elseif (in_array($source, $bonus, true)) {
            $data[$add ? 'money_in' : 'money_out'] = $amount;
        } elseif (in_array($source, [TxnSource::GameBank, TxnSource::Jackpot], true)) {
            $data[$add ? 'type_in' : 'type_out'] = $amount;
        }

        return $data;
    }

    private function lockWallet(User $user): Wallet
    {
        $wallet = $user->wallet()->lockForUpdate()->first()
            ?? $user->wallet()->create([
                'currency' => $user->currency ?? $user->shop?->currency ?? Currency::default(),
            ]);

        /** @var Wallet $wallet */
        return $wallet;
    }

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
    }
}
