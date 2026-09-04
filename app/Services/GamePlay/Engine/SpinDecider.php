<?php

namespace App\Services\GamePlay\Engine;

use App\Services\GamePlay\GameConfig;
use App\Services\GamePlay\GameContext;

/**
 * The "garant" — decides win / bonus / none for a spin.
 *
 * Faithful port of legacy VanguardLTE SlotSettings::GetSpinSettings:
 *
 *  1. The DB win-chance tables (`win_chances`) are THE control. `spin` = 1/N
 *     chance of a paying spin, `bonus` = 1/N chance of a feature — looked up by
 *     active line-count bucket × the target-RTP band. Lower N = wins more often.
 *
 *  2. A slow self-correcting loop only kicks in AFTER `rtp_control_window` spins
 *     (default 200) and only while realised RTP (total_win / total_bet) is
 *     running over target. During such a "clamp episode" wins are forced rare
 *     (spinChance→cold_spin_chance), bonuses cold (bonusChance→cold_bonus_chance)
 *     and small (cap→correction_max_win). It clears once RTP is back in band.
 *     The rest of the time the game plays purely at the configured odds.
 *
 * Loop counters live on `games.engine_state` (legacy game.advanced blob):
 *   rtp_count  — spins left until the next correction check (counts down)
 *   rtp_clamp  — spins left in the current clamp episode (0 = not clamping)
 */
class SpinDecider
{
    public function decide(GameContext $context, string $event, int $lineCount, float $stake = 0.0): SpinDecision
    {
        $config = $context->config();
        $shopRtp = max(1.0, $context->rtpTarget());
        $ctrl = $config->rtpControl();
        $window = $config->rtpControlWindow();

        // 1) base odds straight from the win-chance tables
        $spinChance = max(1, $config->winChance('spin', $lineCount, $shopRtp));
        $bonusChance = max(1, $config->winChance('bonus', $lineCount, $shopRtp));

        $game = $context->game;
        $actualRtp = $context->actualRtp();
        $bankAvailable = $context->bankAvailable();
        $capMultiplier = max(0.5, $context->maxWinMultiplier());
        $winScale = 1.0;

        // 2) the slow RTP correction — skipped for demo (off the books) and until
        //    the game has a full control window of turnover to judge by.
        $state = $context->engineState();
        $count = (int) ($state['rtp_count'] ?? $window);
        $clamp = (int) ($state['rtp_clamp'] ?? 0);
        $haveHistory = ! $context->demo && (float) $game->total_bet > 0 && (int) $game->rounds_count >= $window;

        if ($haveHistory) {
            if ($count > 0) {
                $count--;
            } else {
                // Window elapsed — now check every spin (legacy: RtpControlCount
                // goes negative and stays there until RTP is back in band).
                // Arm / re-arm a clamp episode whenever we're paying over target.
                if ($clamp <= 0 && $actualRtp > $shopRtp + random_int(1, 2)) {
                    $clamp = random_int($ctrl['clamp_spins'][0], $ctrl['clamp_spins'][1]);
                }
                if ($clamp <= 0 && $actualRtp >= $shopRtp - 1 && $actualRtp <= $shopRtp + 2) {
                    $count = $window;   // settled on target — resume normal play
                }
            }

            if ($clamp > 0) {
                $clamp--;
                $spinChance = max($spinChance, $ctrl['cold_spin_chance']);
                $bonusChance = max($bonusChance, $ctrl['cold_bonus_chance']);
                $capMultiplier = max(0.5, min($capMultiplier, (float) $ctrl['correction_max_win']));
                $winScale = 0.5;
                if ($actualRtp <= $shopRtp + 4) {   // roughly back in band — ease off
                    $clamp = 0;
                    $count = (int) round($window / 4);
                }
            } elseif ($count <= 0 && $actualRtp > 0 && $actualRtp < $shopRtp - 4) {
                // well behind target — let wins come a little more freely
                $spinChance = max(1, (int) round($spinChance * 0.7));
                $winScale = 1.3;
            }
        }

        $context->putEngineState(['rtp_count' => $count, 'rtp_clamp' => $clamp]);

        // --- the roll ------------------------------------------------------
        $make = fn (string $type): SpinDecision => new SpinDecision(
            $type, $bankAvailable, $capMultiplier, $spinChance, $shopRtp, $winScale,
        );

        if ($config->hasBonus() && random_int(1, $bonusChance) === 1) {
            // Legacy bonus gate: only once turnover covers an average feature
            // payout and the pool can afford it. Demo has no turnover on the
            // books, so let features play.
            $avgFeature = $this->averagePayout($config) * max($stake, 0.0001);
            $turnoverOk = $context->demo
                || (float) $game->total_bet >= (float) $game->total_win + $avgFeature;
            if ($turnoverOk && $bankAvailable >= $avgFeature) {
                return $make('bonus');
            }
        }

        if (random_int(1, $spinChance) === 1) {
            return $make('win');
        }

        // Near-broke nudge (legacy: balance ~empty → small forced win).
        if ($event === 'spin' && $context->balance() <= 2 * $context->denomination() && random_int(1, 10) === 1) {
            return $make('win');
        }

        return $make('none');
    }

    /** Mean of each paying symbol's *smallest* payout (legacy CheckBonusWin). */
    private function averagePayout(GameConfig $config): float
    {
        $firsts = [];
        foreach ($config->paytable() as $row) {
            foreach ((array) $row as $v) {
                if ($v > 0) {
                    $firsts[] = (float) $v;
                    break;
                }
            }
        }

        return $firsts === [] ? 0.0 : array_sum($firsts) / count($firsts);
    }
}
