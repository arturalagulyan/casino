<?php

namespace App\Services\GamePlay;

use App\Enums\BankType;
use App\Enums\Currency;
use App\Enums\TxnDirection;
use App\Enums\TxnSource;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameLog;
use App\Models\GameRound;
use App\Models\GameSession;
use App\Models\Jackpot;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserBank;
use App\Models\Wallet;
use App\Services\Banker;
use App\Services\Fx;
use App\Services\Ledger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The platform side of a running game — the universal backend every game server
 * talks to. Ports the legacy VanguardLTE\Games\*\SlotSettings surface
 * (GetBalance / SetBalance / SetBank / UpdateJackpots / SaveLogReport / …) onto
 * the rebuild's Ledger + Banker + game_rounds / game_logs / jackpots.
 *
 * A game server never touches wallets, banks or jackpots directly — it asks this.
 */
class GameContext
{
    public readonly Shop $shop;

    public readonly User $user;

    public readonly Game $game;

    public readonly Currency $currency;

    /**
     * Demo play: the player is a `free_demo` account (spun up by DemoLauncher so
     * staff can test a game). Every money move stays on the demo wallet only —
     * no bank, no jackpots, no transactions, no game_rounds, no RTP stats.
     */
    public readonly bool $demo;

    /**
     * Per-round bank split, filled by placeBet(), read by recordRound().
     *
     * @var array{bank: float, jackpot: float, profit: float}
     */
    private array $split = ['bank' => 0.0, 'jackpot' => 0.0, 'profit' => 0.0];

    private ?GameConfig $config = null;

    /** The pool this round settles against — resolved once per context. */
    private GameBank|UserBank|null $settlement = null;

    /** Memoised activeUserBank() lookup — false = not resolved yet. */
    private UserBank|false|null $userBank = false;

    public function __construct(
        User $user,
        Game $game,
        private readonly Ledger $ledger,
        private readonly Banker $banker,
    ) {
        $game->loadMissing('shop', 'template', 'jackpot');

        $this->user = $user;
        $this->game = $game;
        $this->shop = $game->shop;
        $this->currency = $this->wallet()->currency;
        $this->demo = (bool) ($user->free_demo ?? false);
    }

    // ---- read ---------------------------------------------------------

    public function config(): GameConfig
    {
        return $this->config ??= new GameConfig($this->game->template, $this->game, $this->currency);
    }

    public function wallet(): Wallet
    {
        /** @var Wallet */
        return $this->user->wallet()->firstOrCreate([], [
            'currency' => $this->user->currency ?? $this->shop->currency,
        ]);
    }

    public function balance(): float
    {
        return (float) $this->wallet()->balance;
    }

    public function denomination(): float
    {
        return $this->config()->denomination();
    }

    /**
     * Target payout %. Individual-RTP override on the player's active user bank
     * → game bank override → per-game override → shop default.
     */
    public function rtpTarget(): float
    {
        $userBank = $this->activeUserBank();

        if ($userBank && $userBank->temp_rtp !== null) {
            return (float) $userBank->temp_rtp;
        }

        return (float) ($this->game->bank()?->temp_rtp
            ?? $this->game->rtp_percent
            ?? $this->shop->rtp_percent
            ?? 90);
    }

    /** Single-win cap, × bet (per-game override → shop). */
    public function maxWinMultiplier(): float
    {
        return (float) ($this->game->max_win_multiplier
            ?? $this->shop->max_win_multiplier
            ?: 1000);
    }

    public function maxWin(float $bet): float
    {
        return $bet * $this->maxWinMultiplier();
    }

    /** Actual RTP so far, % (legacy stat_out / stat_in). */
    public function actualRtp(): float
    {
        $bet = (float) $this->game->total_bet;

        return $bet > 0 ? (float) $this->game->total_win / $bet * 100 : 0.0;
    }

    /**
     * Per-game RTP-feedback loop state (legacy game.advanced blob).
     *
     * @return array<string, mixed>
     */
    public function engineState(): array
    {
        return $this->game->engine_state ?? [];
    }

    /** @param array<string, mixed> $values */
    public function putEngineState(array $values): void
    {
        if ($this->demo) {
            return;   // demo play must not nudge the live RTP-feedback loop
        }

        $this->game->engine_state = array_replace($this->game->engine_state ?? [], $values);
        $this->game->saveQuietly();
    }

    /** @return list<float> */
    public function betOptions(): array
    {
        return $this->config()->betOptions();
    }

    public function bank(): ?GameBank
    {
        return $this->shop->bank($this->currency);
    }

    public function poolType(): BankType
    {
        return $this->game->bank_type ?? BankType::Slots;
    }

    /**
     * The player's own liquidity pool when an admin has switched manipulation
     * on for them (user_banks.is_active) — otherwise null. Demo never has one.
     */
    public function activeUserBank(): ?UserBank
    {
        if ($this->userBank !== false) {
            return $this->userBank;
        }

        if ($this->demo) {
            return $this->userBank = null;
        }

        $bank = $this->user->userBankFor($this->currency);

        return $this->userBank = ($bank && $bank->is_active ? $bank : null);
    }

    /**
     * The pool a win is paid FROM: the player's active UserBank when an admin
     * has manipulation switched on, otherwise the shop GameBank (the pool shared
     * by every game of this bank type in the shop). Losing stakes always feed
     * the shop pool regardless — see placeBet(). Resolved once.
     */
    public function settlementBank(): GameBank|UserBank
    {
        return $this->settlement ??= $this->activeUserBank() ?? $this->ensureBank();
    }

    public function usingUserBank(): bool
    {
        return $this->settlementBank() instanceof UserBank;
    }

    /**
     * How much the win pool can afford to pay out right now. Never negative:
     * an empty pool means no wins until it is fed back up — the shop pool by
     * players losing, a user bank by an admin (legacy GetBank / SetBank).
     */
    public function bankAvailable(): float
    {
        if ($this->demo) {
            return PHP_FLOAT_MAX;   // demo pays from nowhere — never bank-starved
        }

        return max(0.0, (float) $this->settlementBank()->{$this->poolType()->column()});
    }

    /** Move the win pool's balance by $delta (row-locked). */
    private function moveWinPool(float $delta): void
    {
        if ($delta === 0.0) {
            return;
        }

        $bank = $this->settlementBank();
        $column = $this->poolType()->column();

        DB::transaction(function () use ($bank, $column, $delta): void {
            /** @var GameBank|UserBank $locked */
            $locked = $bank->newQuery()->whereKey($bank->getKey())->lockForUpdate()->firstOrFail();

            if ($delta >= 0) {
                $locked->increment($column, $delta);
            } else {
                $locked->decrement($column, -$delta);
            }
        });

        $fresh = $bank->fresh();

        if ($fresh instanceof Model) {
            $this->settlement = $fresh;
        }
    }

    /** Feed the shop's shared pool (losing stakes / clawed-back shop wins). */
    private function depositShopBank(float $amount): void
    {
        if ($amount <= 0.0) {
            return;
        }

        $bank = $this->ensureBank();
        $column = $this->poolType()->column();

        DB::transaction(function () use ($bank, $column, $amount): void {
            GameBank::whereKey($bank->getKey())->lockForUpdate()->firstOrFail()
                ->increment($column, $amount);
        });
    }

    // ---- write --------------------------------------------------------

    /**
     * Take the stake: debit the player, feed the shop bank + jackpots.
     * Throws if the player can't cover it.
     */
    public function placeBet(float $stake): void
    {
        if ($stake <= 0) {
            throw new RuntimeException('Bet must be greater than zero.');
        }

        if ($this->demo) {
            $this->debitDemo($stake, 'Bet must not exceed the demo balance.');
            $this->split = ['bank' => 0.0, 'jackpot' => 0.0, 'profit' => 0.0];

            return;
        }

        $this->ledger->adjustPlayer(
            $this->user, $stake, TxnDirection::Debit, $this->user, TxnSource::Bet,
            context: ['game' => $this->game->id],
            title: $this->game->template->title ?? $this->game->title,
        );

        $toBank = round($stake * $this->rtpTarget() / 100, 4);

        $toJackpot = 0.0;
        foreach ($this->jackpots() as $jackpot) {
            $this->banker->contributeToJackpot($jackpot, $stake, $this->currency);
            $toJackpot += round($stake * (float) $jackpot->contribution_percent / 100, 4);
        }

        // The losing stake ALWAYS feeds the shop's shared pool — a manipulated
        // player still grows the bank for everyone else. Only the win side is
        // diverted to their user bank (see settlementBank / awardWin).
        $this->depositShopBank($toBank);

        $this->split = [
            'bank' => $toBank,
            'jackpot' => round($toJackpot, 4),
            'profit' => round($stake - $toBank - $toJackpot, 4),
        ];
    }

    /**
     * Pay a win: credit the player, drain the settlement pool, sweep any
     * overflow. A win can never take the pool below zero (legacy SetBank
     * hard-abort) — upstream gating (SpinDecider / SlotEngine ceiling) should
     * already keep wins within budget, so a clamp here means an engine let one
     * through it shouldn't have. We clamp anyway and leave a trail.
     *
     * @return float the amount actually paid (== $win unless the pool was short)
     */
    public function awardWin(float $win): float
    {
        if ($win <= 0) {
            return 0.0;
        }

        if ($this->demo) {
            $this->wallet()->increment('balance', $win);

            return $win;
        }

        $available = $this->bankAvailable();
        $paid = min($win, $available);

        if ($paid + 1e-6 < $win) {
            Log::warning('Game win clamped to available bank', [
                'game' => $this->game->id,
                'user' => $this->user->id,
                'requested' => $win,
                'paid' => $paid,
                'bank_available' => $available,
                'settled_against' => $this->usingUserBank() ? 'user_bank' : 'game_bank',
            ]);
        }

        if ($paid <= 0) {
            return 0.0;
        }

        $this->ledger->adjustPlayer(
            $this->user, $paid, TxnDirection::Credit, $this->user, TxnSource::Win,
            context: ['game' => $this->game->id],
            title: $this->game->template->title ?? $this->game->title,
        );

        $settlement = $this->settlementBank();
        $this->moveWinPool(-$paid);

        if ($settlement instanceof GameBank) {
            $this->banker->sweepOverflow($settlement->refresh(), $this->poolType(), $this->shop->owner);
        }

        return $paid;
    }

    /**
     * Player loses an already-credited amount straight back to the pool it came
     * from — a gamble/double-up loss. Returns to the win pool (a manipulated
     * player's user bank, else the shop bank), symmetric with awardWin. No
     * jackpot feed, no RTP split (it isn't a stake).
     */
    public function clawback(float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        if ($this->demo) {
            $this->wallet()->decrement('balance', min($amount, $this->balance()));

            return;
        }

        $this->ledger->adjustPlayer(
            $this->user, $amount, TxnDirection::Debit, $this->user, TxnSource::Bet,
            context: ['game' => $this->game->id, 'kind' => 'gamble-loss'],
            title: $this->game->template->title ?? $this->game->title,
        );

        $this->moveWinPool($amount);
    }

    /** Award a jackpot pot to this player (drop triggered by the game server). */
    public function awardJackpot(Jackpot $jackpot): float
    {
        if ($this->demo) {
            $amount = app(Fx::class)->convert(
                (float) $jackpot->balance, $jackpot->poolCurrency(), $this->currency,
            );
            $this->wallet()->increment('balance', $amount);

            return $amount;
        }

        $txn = $this->ledger->payoutJackpot($jackpot, $this->user, $this->user, ['game' => $this->game->id]);

        return (float) $txn->amount;
    }

    /** Persist the round: game_rounds + game_logs + running game stats. */
    public function recordRound(SpinResult $result, string $rawPayload): GameRound
    {
        if ($this->demo) {
            $this->split = ['bank' => 0.0, 'jackpot' => 0.0, 'profit' => 0.0];

            // Audited nowhere — hand back a transient row so callers that read
            // it back (win totals in a response, …) still work.
            return new GameRound([
                'shop_id' => $this->shop->id,
                'user_id' => $this->user->id,
                'game_id' => $this->game->id,
                'game_code' => $this->game->template->code ?? (string) $this->game->id,
                'currency' => $this->currency,
                'bet' => $result->bet,
                'win' => $result->win,
                'balance_after' => $this->balance(),
                'denomination' => $this->denomination(),
                'status' => 0,
                'played_at' => now(),
            ]);
        }

        $shopBank = $this->ensureBank();
        $snapshot = [
            'win_bank' => $this->usingUserBank() ? 'user_bank' : 'game_bank',
            'slots' => (float) $shopBank->slots, 'little' => (float) $shopBank->little,
            'table_bank' => (float) $shopBank->table_bank, 'bonus' => (float) $shopBank->bonus,
            'fish' => (float) $shopBank->fish, 'total' => (float) $shopBank->total(),
        ];

        if ($this->usingUserBank()) {
            $userBank = $this->settlementBank();
            $snapshot['user_bank'] = [
                'slots' => (float) $userBank->slots, 'little' => (float) $userBank->little,
                'table_bank' => (float) $userBank->table_bank, 'bonus' => (float) $userBank->bonus,
                'fish' => (float) $userBank->fish,
            ];
        }

        $round = GameRound::create([
            'shop_id' => $this->shop->id,
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'game_code' => $this->game->template->code ?? (string) $this->game->id,
            'currency' => $this->currency,
            'bet' => $result->bet,
            'win' => $result->win,
            'balance_after' => $this->balance(),
            'stake_to_bank' => $this->split['bank'],
            'stake_to_jackpot' => $this->split['jackpot'],
            'stake_to_profit' => $this->split['profit'],
            'denomination' => $this->denomination(),
            'bank_snapshot' => $snapshot,
            'status' => 0,
            'played_at' => now(),
        ]);

        GameLog::create([
            'shop_id' => $this->shop->id,
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'ip' => request()->ip() ?? '0.0.0.0',
            'payload' => $rawPayload,
        ]);

        $this->game->increment('rounds_count');

        // Slot RTP (total_win / total_bet) counts spins, free spins and bonus
        // payouts only. Free spins / bonus picks carry bet = 0 (the stake was
        // counted on the triggering spin). The double-up gamble is a wallet-level
        // side bet — audited as a round, but kept out of the slot RTP figure.
        if ($result->state !== 'gamble') {
            if ($result->bet > 0) {
                $this->game->increment('total_bet', $result->bet);
            }
            if ($result->win > 0) {
                $this->game->increment('total_win', $result->win);
            }
        }

        $this->user->forceFill(['last_bet_at' => now()])->saveQuietly();

        $this->split = ['bank' => 0.0, 'jackpot' => 0.0, 'profit' => 0.0];

        return $round;
    }

    // ---- per-game session state (legacy user.session blob) ------------

    public function session(): GameSession
    {
        return GameSession::firstOrCreate(
            ['user_id' => $this->user->id, 'game_id' => $this->game->id],
            ['token' => (string) str()->uuid(), 'is_active' => true, 'last_seen_at' => now()],
        );
    }

    public function stateGet(string $key, mixed $default = null): mixed
    {
        return data_get($this->session()->state ?? [], $key, $default);
    }

    public function statePut(array $values): void
    {
        $session = $this->session();
        $session->update([
            'state' => array_replace($session->state ?? [], $values),
            'last_seen_at' => now(),
        ]);
    }

    public function stateClear(): void
    {
        $this->session()->update(['state' => null]);
    }

    // ---- internals ---------------------------------------------------

    /** @return Collection<int, Jackpot> */
    public function jackpots(): Collection
    {
        return Jackpot::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('shop_id', $this->shop->id)->orWhereNull('shop_id'))
            ->when($this->game->jackpot_id, fn ($q) => $q->orWhere('id', $this->game->jackpot_id))
            ->get();
    }

    private function debitDemo(float $amount, string $message): void
    {
        $wallet = $this->wallet();

        if ((float) $wallet->balance < $amount) {
            throw new RuntimeException($message);
        }

        $wallet->decrement('balance', $amount);
    }

    private function ensureBank(): GameBank
    {
        /** @var GameBank */
        return $this->shop->banks()->firstOrCreate(
            ['currency' => $this->currency->value],
        );
    }
}
