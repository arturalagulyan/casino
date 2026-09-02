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
use App\Models\Wallet;
use App\Services\Banker;
use App\Services\Ledger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        return $this->config ??= new GameConfig($this->game->template, $this->game);
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

    /** Target payout %, game bank override → per-game override → shop default. */
    public function rtpTarget(): float
    {
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

    /** How much the shop bank pool can afford to pay out right now. */
    public function bankAvailable(): float
    {
        if ($this->demo) {
            return PHP_FLOAT_MAX;   // demo pays from nowhere — never bank-starved
        }

        $bank = $this->bank();

        return $bank ? max(0.0, (float) $bank->{$this->poolType()->column()}) : 0.0;
    }

    // ---- write --------------------------------------------------------

    /**
     * Take the stake: debit the player, feed the bank + jackpots.
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

        $bank = $this->ensureBank();
        $toBank = round($stake * $this->rtpTarget() / 100, 4);

        $toJackpot = 0.0;
        foreach ($this->jackpots() as $jackpot) {
            $before = (float) $jackpot->balance;
            $new = $this->banker->contributeToJackpot($jackpot, $stake);
            $toJackpot += max(0.0, $new - $before);
        }

        DB::transaction(function () use ($bank, $toBank) {
            GameBank::whereKey($bank->id)->lockForUpdate()->firstOrFail()
                ->increment($this->poolType()->column(), $toBank);
        });

        $this->split = [
            'bank' => $toBank,
            'jackpot' => round($toJackpot, 4),
            'profit' => round($stake - $toBank - $toJackpot, 4),
        ];
    }

    /** Pay a win: credit the player, drain the bank pool, sweep any overflow. */
    public function awardWin(float $win): void
    {
        if ($win <= 0) {
            return;
        }

        if ($this->demo) {
            $this->wallet()->increment('balance', $win);

            return;
        }

        $this->ledger->adjustPlayer(
            $this->user, $win, TxnDirection::Credit, $this->user, TxnSource::Win,
            context: ['game' => $this->game->id],
            title: $this->game->template->title ?? $this->game->title,
        );

        $bank = $this->ensureBank();

        DB::transaction(function () use ($bank, $win) {
            GameBank::whereKey($bank->id)->lockForUpdate()->firstOrFail()
                ->decrement($this->poolType()->column(), $win);
        });

        $this->banker->sweepOverflow($bank->refresh(), $this->poolType(), $this->shop->owner);
    }

    /**
     * Player loses an already-credited amount straight back to the bank pool —
     * a gamble/double-up loss. No jackpot feed, no RTP split (it isn't a stake).
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

        $bank = $this->ensureBank();

        DB::transaction(function () use ($bank, $amount) {
            GameBank::whereKey($bank->id)->lockForUpdate()->firstOrFail()
                ->increment($this->poolType()->column(), $amount);
        });
    }

    /** Award a jackpot pot to this player (drop triggered by the game server). */
    public function awardJackpot(Jackpot $jackpot): float
    {
        if ($this->demo) {
            $amount = (float) $jackpot->balance;
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

        $bank = $this->bank();
        $snapshot = $bank ? [
            'slots' => (float) $bank->slots, 'little' => (float) $bank->little,
            'table_bank' => (float) $bank->table_bank, 'bonus' => (float) $bank->bonus,
            'fish' => (float) $bank->fish, 'total' => (float) $bank->total(),
        ] : null;

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
