<?php

namespace App\Services\GamePlay;

use App\Enums\Currency;
use App\Models\Jackpot;
use App\Models\JackpotWin;
use App\Services\Fx;
use Illuminate\Support\Carbon;
use Workerman\Connection\TcpConnection;

/**
 * Live jackpot ticker channel for `php artisan game:socket` — separate from the
 * per-game wire protocols in {@see SocketServer}. A client subscribes with
 * {"channel":"jackpots","subscribe":[...jackpot ids...],"userId":...,"currency":...}
 * over the same WebSocket connection and gets balance ticks plus a one-time
 * "won" push when an admin payout (JackpotActions::payout()) matches its userId.
 *
 * Balances are always tracked/diffed internally in each pool's own currency
 * (`$lastBalances`) and only converted to the subscriber's own `currency` right
 * before sending — a display-only FX conversion, same as
 * GameAssetController::jackpotBootstrap(). Pool state itself never changes here.
 *
 * One instance per Workerman worker process (each forked after Worker::runAll(),
 * so every worker's copy only ever tracks the connections it personally holds).
 */
class JackpotChannel
{
    public function __construct(private Fx $fx) {}

    /** @var array<int, array{conn: TcpConnection, jackpotIds: list<int>, userId: ?int, currency: string}> */
    private array $subscribers = [];

    /** @var array<int, float> last raw (pool-currency) balance seen per jackpot id */
    private array $lastBalances = [];

    /** @var array<int, Currency> pool currency per jackpot id, cached at subscribe time */
    private array $poolCurrencies = [];

    private ?Carbon $lastWinCheck = null;

    /** @var array<int, true> jackpot_wins ids already pushed, bounded below */
    private array $seenWinIds = [];

    public function isChannelMessage(mixed $decoded): bool
    {
        return is_array($decoded) && ($decoded['channel'] ?? null) === 'jackpots';
    }

    /** @param array<string, mixed> $message */
    public function handle(TcpConnection $conn, array $message): void
    {
        $ids = array_values(array_map('intval', (array) ($message['subscribe'] ?? [])));
        $userId = isset($message['userId']) ? (int) $message['userId'] : null;
        $currency = Currency::tryFrom((string) ($message['currency'] ?? '')) ?? Currency::default();

        $this->subscribers[$conn->id] = [
            'conn' => $conn,
            'jackpotIds' => $ids,
            'userId' => $userId,
            'currency' => $currency->value,
        ];

        if (! $ids) {
            return;
        }

        $jackpots = Jackpot::query()->whereIn('id', $ids)->get(['id', 'balance', 'currency', 'shop_id']);

        $converted = [];
        foreach ($jackpots as $jackpot) {
            $this->lastBalances[$jackpot->id] = (float) $jackpot->balance;
            $this->poolCurrencies[$jackpot->id] = $jackpot->poolCurrency();
            $converted[$jackpot->id] = $this->fx->convert((float) $jackpot->balance, $jackpot->poolCurrency(), $currency);
        }

        $conn->send(json_encode(['type' => 'snapshot', 'balances' => $converted]));
    }

    public function unsubscribe(TcpConnection $conn): void
    {
        unset($this->subscribers[$conn->id]);
    }

    /** Called every ~1s by a Workerman Timer. */
    public function tick(): void
    {
        if (! $this->subscribers) {
            return;
        }

        $this->pushBalanceChanges();
        $this->pushNewWins();
    }

    private function pushBalanceChanges(): void
    {
        $ids = collect($this->subscribers)->flatMap(fn (array $s) => $s['jackpotIds'])->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $missingPoolCurrency = $ids->reject(fn ($id) => isset($this->poolCurrencies[$id]));
        if ($missingPoolCurrency->isNotEmpty()) {
            Jackpot::query()->whereIn('id', $missingPoolCurrency)->get(['id', 'currency', 'shop_id'])
                ->each(function (Jackpot $j): void {
                    $this->poolCurrencies[$j->id] = $j->poolCurrency();
                });
        }

        $balances = Jackpot::query()->whereIn('id', $ids)->pluck('balance', 'id');

        $changed = [];
        foreach ($balances as $id => $balance) {
            $balance = (float) $balance;
            if (! array_key_exists($id, $this->lastBalances) || abs($this->lastBalances[$id] - $balance) > 0.0000001) {
                $changed[$id] = $balance;
            }
            $this->lastBalances[$id] = $balance;
        }

        if (! $changed) {
            return;
        }

        foreach ($this->subscribers as $sub) {
            $relevant = array_intersect_key($changed, array_flip($sub['jackpotIds']));

            if (! $relevant) {
                continue;
            }

            $currency = Currency::from($sub['currency']);
            $converted = [];
            foreach ($relevant as $id => $rawBalance) {
                $poolCurrency = $this->poolCurrencies[$id] ?? Currency::default();
                $converted[$id] = $this->fx->convert($rawBalance, $poolCurrency, $currency);
            }

            $sub['conn']->send(json_encode(['type' => 'update', 'balances' => $converted]));
        }
    }

    private function pushNewWins(): void
    {
        $since = $this->lastWinCheck ?? now()->subSeconds(15);
        $this->lastWinCheck = now();

        $wins = JackpotWin::query()
            ->with('jackpot:id,name')
            ->where('won_at', '>=', $since)
            ->get(['id', 'jackpot_id', 'user_id', 'amount', 'won_at']);

        foreach ($wins as $win) {
            if (isset($this->seenWinIds[$win->id])) {
                continue;
            }
            $this->seenWinIds[$win->id] = true;

            foreach ($this->subscribers as $sub) {
                if ($sub['userId'] !== null && $sub['userId'] === $win->user_id) {
                    $sub['conn']->send(json_encode([
                        'type' => 'won',
                        'jackpotName' => $win->jackpot->name,
                        'amount' => (float) $win->amount,
                    ]));
                }
            }
        }

        // Bound memory on a long-lived worker process.
        if (count($this->seenWinIds) > 500) {
            $this->seenWinIds = array_slice($this->seenWinIds, -200, preserve_keys: true);
        }
    }
}
