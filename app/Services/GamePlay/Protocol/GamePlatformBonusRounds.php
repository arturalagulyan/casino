<?php

namespace App\Services\GamePlay\Protocol;

use App\Services\GamePlay\Engine\SlotEngine;
use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;
use App\Services\GamePlay\SpinResult;
use Closure;

/**
 * The EGT sub-commands that aren't a plain spin: `gamble`, `collect`, and the
 * pick screens (`multiplierchoice` → `freespinchoice`, `bonuschoice`).
 *
 * Every number comes from `game_templates.bonus_config` via
 * {@see GameConfig::bonusFlowFor()} — no per-game code.
 * $env wraps a payload in the standard GameEventResponse envelope.
 */
class GamePlatformBonusRounds
{
    /**
     * @param  Closure(array): array  $env
     * @return list<array<string, mixed>>
     */
    public function gamble(GameContext $context, array $request, Closure $env): array
    {
        $state = $context->stateGet('features', []);
        $win = (float) ($state['total_win'] ?? 0);

        if ($win <= 0) {
            return [$this->err($request, 'invalid gamble state')];
        }

        $cfg = $context->config();
        $g = app(SlotEngine::class)->gamble($context, $win);
        $guess = (string) ($request['bet']['color'] ?? '1');
        $step = max(0, (int) ($state['gamble_step'] ?? $cfg->gambleConfig()['steps']) - 1);

        if ($g['won']) {
            $context->awardWin($win);                 // the doubled half
            $state['total_win'] = $win * 2;
            $gambleState = 'gamble';
            $dealer = $guess === '1' ? ['0', '3'][random_int(0, 1)] : ['2', '1'][random_int(0, 1)];
        } else {
            $context->clawback($win);
            $state['total_win'] = 0.0;
            $state['gamble_step'] = 0;
            $step = 0;
            $gambleState = 'idle';
            $dealer = $guess === '1' ? ['1', '2'][random_int(0, 1)] : ['3', '0'][random_int(0, 1)];
        }

        $context->recordRound(
            new SpinResult(bet: $g['won'] ? 0.0 : $win, win: $g['won'] ? $win * 2 : 0.0, state: 'gamble'),
            json_encode(['reason' => 'gamble', $g['won'] ? 'won' : 'lost' => $win]),
        );

        $state['gamble_step'] = $step;
        $context->statePut(['features' => $state]);

        return [
            $env([
                'complex' => ['gambles' => $step, 'card' => (int) $dealer, 'jackpot' => false, 'gameCommand' => 'gamble'],
                'state' => $gambleState,
                'winAmount' => (int) round((float) $state['total_win'] * 100),
                'balance' => (int) round($context->balance() * 100),
            ]),
        ];
    }

    /** @param  Closure(array): array  $env */
    public function collect(GameContext $context, array $request, Closure $env): array
    {
        $state = $context->stateGet('features', []);
        $win = (float) ($state['total_win'] ?? 0);
        $state['total_win'] = 0.0;
        $context->statePut(['features' => $state]);

        return $env([
            'complex' => ['gameCommand' => 'collect'],
            'state' => 'idle',
            'winAmount' => (int) round($win * 100),
            'balance' => (int) round($context->balance() * 100),
        ]);
    }

    /**
     * @param  Closure(array): array  $env
     * @return list<array<string, mixed>>
     */
    public function choose(GameContext $context, string $step, array $request, Closure $env): array
    {
        $state = $context->stateGet('features', []);
        $cfg = $context->config();
        $sym = (int) ($state['bonus_symbol'] ?? $cfg->scatterSymbol());
        $params = $cfg->bonusFlowFor($sym)['params'];
        $choice = (int) ($request['bet']['choice'] ?? 0);

        return match ($step) {
            'multiplierchoice' => [$this->pickMultiplier($context, $env, $state, $params, $choice)],
            'freespinchoice' => [$this->pickFreeSpins($context, $env, $state, $params, $choice)],
            'bonuschoice' => [$this->pickMoney($context, $env, $state, $params, $choice)],
            default => [],
        };
    }

    // ---- pick_multiplier_freespins -------------------------------

    private function pickMultiplier(GameContext $context, Closure $env, array $state, array $params, int $choice): array
    {
        [$lo, $hi] = $params['multiplier_range'] ?? [1, 5];

        $multiplier = random_int((int) $lo, (int) $hi);
        $state['multiplier'] = $multiplier;
        $state['bonus_step'] = 'freespin';
        $context->statePut(['features' => $state]);

        // Legacy: choice carries the multiplier and an EMPTY `closed` (the boxes
        // are only revealed at the freespin-count step). `closed` lives INSIDE
        // `choice`, not as a sibling — the client crashes reading choice.closed.
        return $env([
            'complex' => [
                'choice' => $this->box($choice, [
                    'multiplier' => $multiplier, 'mult' => $multiplier, 'closed' => [],
                ]),
                'multiplier' => $multiplier,
                'choices' => 1,
                'gameCommand' => 'multiplierchoice',
            ],
            'state' => 'freespinchoice',
            'winAmount' => (int) round((float) ($state['bonus_win'] ?? 0) * 100),
            'balance' => (int) round($context->balance() * 100),
        ]);
    }

    private function pickFreeSpins(GameContext $context, Closure $env, array $state, array $params, int $choice): array
    {
        [$flo, $fhi] = $params['free_spins_range'] ?? [5, 12];
        [$wlo, $whi] = $params['extra_wild_range'] ?? [-1, -1];
        $picks = max(3, (int) ($params['freespin_picks'] ?? 8));

        $spins = random_int((int) $flo, (int) $fhi);
        $hasWild = ! ((int) $wlo === -1 && (int) $whi === -1);
        $wild = $hasWild ? random_int((int) $wlo, (int) $whi) : -1;

        $state['free_spins_left'] = $spins;
        $state['free_spins_total'] = $spins;
        $state['free_spins_used'] = 0;
        $state['extra_wild'] = $wild;
        $state['bonus_step'] = null;
        $context->statePut(['features' => $state]);

        // Reveal all boxes: the chosen one holds the real spins/wild, the rest
        // are decoys. `closed` lives INSIDE `choice` (legacy shape).
        $closed = [];
        for ($i = 0; $i < $picks; $i++) {
            $closed[] = $i === $choice
                ? $this->box($i, ['freespins' => $spins, 'fs' => $spins, 'wildcard' => $wild])
                : $this->box($i, [
                    'freespins' => random_int((int) $flo, (int) $fhi),
                    'fs' => random_int((int) $flo, (int) $fhi),
                    'wildcard' => $hasWild && random_int(1, 3) === 1 ? random_int((int) $wlo, (int) $whi) : -1,
                ]);
        }

        return $env([
            'complex' => [
                'choice' => $this->box($choice, [
                    'freespins' => $spins, 'fs' => $spins, 'wildcard' => $wild, 'closed' => $closed,
                ]),
                'freespins' => $spins,
                'choices' => 1,
                'gameCommand' => 'freespinchoice',
            ],
            'state' => 'freespin',
            'winAmount' => (int) round((float) ($state['bonus_win'] ?? 0) * 100),
            'balance' => (int) round($context->balance() * 100),
        ]);
    }

    // ---- pick_money (BANK BONUS) -------------------------------
    //
    // Legacy Action Money: the BANK BONUS symbol pays an instant cash multiplier
    // AND then leads into the free-games bonus. So a `bonuschoice` pick awards
    // the cash, then hands off to the multiplier pick (`state: multiplierchoice`)
    // — the same multiplier → freespinchoice → freespin chain the scatter runs.
    // Returning `idle`/`gamble` here (as this used to) froze the client, which
    // waits for the multiplier screen after a bank pick.

    private function pickMoney(GameContext $context, Closure $env, array $state, array $params, int $choice): array
    {
        $cfg = $context->config();
        $ladder = array_map('intval', $params['multipliers'] ?? [2, 3, 5, 10]);
        $picks = (int) ($params['picks'] ?? 3);
        if (isset($params['extra_pick_at'][(string) ($state['bonus_scatter_count'] ?? 3)])) {
            $picks = (int) $params['extra_pick_at'][(string) $state['bonus_scatter_count']];
        }

        $bet = (float) ($state['bonus_bet'] ?? 0);
        $bank = $context->bankAvailable();

        // Pick a multiplier the bank can afford.
        shuffle($ladder);
        $mult = $ladder[0];
        foreach ($ladder as $m) {
            if ($m * $bet <= $bank) {
                $mult = $m;
                break;
            }
        }

        $win = $mult * $bet;
        $context->awardWin($win);
        $context->recordRound(new SpinResult(bet: 0, win: $win, state: 'bonus'), json_encode(['reason' => 'pick_money', 'mult' => $mult]));

        $total = $win + (float) ($state['total_win'] ?? 0);
        $state['total_win'] = $total;
        $state['bonus_win'] = $total;
        // hand off to the free-games multiplier pick, using the scatter's
        // pick_multiplier_freespins params
        $state['bonus_step'] = 'multiplier';
        $state['bonus_symbol'] = $cfg->scatterSymbol() ?? $cfg->bonusSymbol();
        $context->statePut(['features' => $state]);

        $boxes = [];
        for ($i = 0; $i < max(3, $picks); $i++) {
            $c = $i === $choice ? $mult : $ladder[array_rand($ladder)];
            $boxes[] = ['coef' => $c, 'bonus' => $i === $choice, 'totalWin' => (int) round($c * $bet * 100)];
        }

        return $env([
            'complex' => [
                'bonuschoiceObject' => ['selectedIndex' => $choice, 'totalBet' => (int) round($bet * 100), 'options' => $boxes],
                'freespins' => 0,
                'multiplier' => 1,
                'choices' => 0,
                'gambles' => (int) ($cfg->gambleConfig()['steps'] ?? 5),
                'gameCommand' => 'bonuschoice',
            ],
            'state' => 'multiplierchoice',
            'winAmount' => (int) round($total * 100),
            'balance' => (int) round($context->balance() * 100),
        ]);
    }

    // ---- box helpers -----------------------------------------

    private function box(int $pos, array $extra): array
    {
        return array_merge([
            'pos' => $pos, 'multiplier' => 0, 'freespins' => 0, 'wildcard' => -1,
            'mult' => 0, 'fs' => 0, 'play' => true,
        ], $extra);
    }

    private function err(array $request, string $message): array
    {
        return [
            'responseEvent' => 'error',
            'responseType' => (string) ($request['bet']['gameCommand'] ?? ''),
            'serverResponse' => $message,
            'messageId' => (string) ($request['messageId'] ?? ''),
        ];
    }
}
