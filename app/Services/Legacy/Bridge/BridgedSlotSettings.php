<?php

namespace App\Services\Legacy\Bridge;

use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use RuntimeException;

/**
 * What a legacy VanguardLTE `SlotSettings` did against the old database —
 * balance, game bank, per-player game data, round log — done against our
 * {@see GameContext} instead. A legacy package's own SlotSettings is compiled
 * on top of this ({@see LegacyServerBridge}) keeping only its game logic
 * (card scoring, box formatting…), so its Server.php runs unchanged.
 *
 * Money: SetBalance(-x, 'bet') is the stake (placeBet), a positive SetBalance
 * a win (awardWin), any other debit a clawback; the game bank is ours
 * (GetBank = what the pool can pay, SetBank a no-op — placeBet/awardWin keep
 * the books).
 */
class BridgedSlotSettings
{
    /** @var list<float|string> */
    public array $Bet = [];

    public string $slotId;

    public int $playerId;

    public float $Percent;

    public float $CurrentDenom = 1.0;

    public float $MaxWin;

    public float $Balance = 0.0;

    public float $Bank = 0.0;

    /** @var array<string, mixed> */
    public array $gameData = [];

    /** @var array<string, mixed> */
    public array $gameDataStatic = [];

    public string $lastEvent = '';

    public bool $isBonusStart = false;

    public function __construct(protected GameContext $ctx, string $slotId)
    {
        $this->slotId = $slotId;
        $this->playerId = (int) $ctx->session()->user_id;
        $this->Bet = array_map(fn ($b) => round((float) $b * $ctx->denomination(), 2), $ctx->betOptions());
        $this->Percent = $ctx->rtpTarget();
        $this->MaxWin = $ctx->maxWinMultiplier();
        $this->Balance = $ctx->balance();
        $stored = (array) $ctx->stateGet('legacy', []);
        $this->gameData = (array) ($stored['data'] ?? []);
        $this->gameDataStatic = (array) ($stored['static'] ?? []);
    }

    public function is_active(): bool
    {
        return true;
    }

    public function SetGameData($key, $value): void
    {
        $this->gameData[$key] = ['timelife' => time() + 86400, 'payload' => $value];
    }

    public function GetGameData($key)
    {
        return $this->gameData[$key]['payload'] ?? 0;
    }

    public function HasGameData($key): bool
    {
        return isset($this->gameData[$key]);
    }

    public function SetGameDataStatic($key, $value): void
    {
        $this->gameDataStatic[$key] = ['timelife' => time() + 86400, 'payload' => $value];
    }

    public function GetGameDataStatic($key)
    {
        return $this->gameDataStatic[$key]['payload'] ?? 0;
    }

    public function HasGameDataStatic($key): bool
    {
        return isset($this->gameDataStatic[$key]);
    }

    public function SaveGameData(): void
    {
        $this->ctx->statePut(['legacy' => ['data' => $this->gameData, 'static' => $this->gameDataStatic]]);
    }

    public function SaveGameDataStatic(): void
    {
        $this->SaveGameData();
    }

    public function GetBalance(): float
    {
        return $this->ctx->balance();
    }

    public function SetBalance($sum, $slotEvent = '')
    {
        $sum = round((float) $sum, 4);
        if ($sum < 0 && $slotEvent === 'bet') {
            $this->ctx->placeBet(-$sum);
        } elseif ($sum < 0) {
            $this->ctx->clawback(-$sum);
        } elseif ($sum > 0) {
            $this->ctx->awardWin($sum);
        }
        $this->Balance = $this->ctx->balance();

        return $this;
    }

    public function GetBank($slotState = '')
    {
        $this->Bank = min($this->ctx->bankAvailable(), 1e12);

        return $this->Bank / $this->CurrentDenom;
    }

    public function SetBank($slotState = '', $sum = 0, $slotEvent = '')
    {
        return $this;
    }

    public function GetPercent()
    {
        return $this->Percent;
    }

    public function GetCountBalanceUser()
    {
        return $this->ctx->balance();
    }

    public function UpdateJackpots($bet): void {}

    /** The player's last logged event except gambles (legacy read it back from game_log). */
    public function GetHistory()
    {
        $this->lastEvent = (string) ($this->gameDataStatic['__lastEvent']['payload'] ?? 'NULL');

        return $this->lastEvent;
    }

    public function SaveLogReport($spinSymbols, $bet, $lines, $win, $slotState): void
    {
        $event = json_decode(is_string($spinSymbols) ? $spinSymbols : '', false);
        if (is_object($event) && ($event->responseEvent ?? '') !== 'gambleResult') {
            $this->SetGameDataStatic('__lastEvent', $spinSymbols);
        }
        $this->ctx->recordRound(
            new SpinResult(bet: (float) $bet, win: (float) $win, state: (string) $slotState),
            json_encode(['responseEvent' => 'spin', 'serverResponse' => is_string($spinSymbols) ? $spinSymbols : json_encode($spinSymbols)]) ?: '{}',
        );
    }

    public function HexFormat($num)
    {
        return strlen(dechex((int) $num)).dechex((int) $num);
    }

    public function InternalError($errcode)
    {
        throw new RuntimeException('legacy game error: '.(is_string($errcode) ? $errcode : json_encode($errcode)));
    }

    public function InternalErrorSilent($errcode)
    {
        $this->InternalError($errcode);
    }
}
