<?php

namespace App\Services\GamePlay\NetEnt\Table;

use RuntimeException;

/**
 * One blackjack round: the rules, nothing about money or wire formats. The
 * state is a plain array (kept in the game session between requests).
 *
 * Rules (set per NetEnt table, checked against their servers):
 *   decks          shoe size (a fresh shuffle every round)
 *   peek           the dealer takes a hole card and checks a ten / ace for
 *                  blackjack at once (Blackjack Touch); without it the dealer's
 *                  second card comes after the players (no hole card)
 *   h17            the dealer hits soft 17
 *   double         the hard totals a two-card hand may double on ([] = any)
 *   das, resplit   double after a split, split a split hand again
 *   splitTens      any two ten-valued cards split (K-Q), not only a pair
 *   enhcLosesAll   no hole card: a dealer blackjack takes doubles and splits
 *                  too (else only the original bet)
 *   splitAcesOne   split aces take one card each and stand
 *
 * Blackjack pays 3:2, insurance 2:1, even money 1:1. When every hand is
 * bust, or every hand is a blackjack, the dealer only completes a two-card
 * hand — a mix (a blackjack beside a bust box) and the dealer plays out.
 */
final class BlackjackRound
{
    private const string RANKS = 'A23456789TJQK';

    /** @param  array<string, mixed>  $rules */
    public function __construct(public readonly array $rules) {}

    /**
     * @param  array<int, int>  $bets  box => cents
     * @param  list<string>  $shoe  cards in dealing order
     * @return array<string, mixed>
     */
    public function deal(array $bets, array $shoe): array
    {
        $s = ['shoe' => $shoe, 'boxes' => [], 'dealer' => [], 'hole' => null, 'phase' => 'play', 'active' => null];
        foreach ($bets as $box => $cents) {
            $s['boxes'][$box] = ['bet' => $cents, 'insurance' => 0, 'asked' => false, 'evenmoney' => false,
                'hands' => [['cards' => [], 'bet' => $cents, 'doubled' => false, 'split' => false, 'aces' => false, 'done' => false]]];
        }
        foreach ([0, 1] as $round) {   // a card to each box, then the dealer's; twice
            foreach (array_keys($s['boxes']) as $box) {
                $s['boxes'][$box]['hands'][0]['cards'][] = $this->draw($s);
            }
            if ($round === 0) {
                $s['dealer'][] = $this->draw($s);
            }
        }
        if ($this->rules['peek']) {
            $s['hole'] = $this->draw($s);
        }
        foreach (array_keys($s['boxes']) as $box) {
            $s['boxes'][$box]['hands'][0]['done'] = $this->isBlackjack($s['boxes'][$box]['hands'][0]);
        }
        $up = $s['dealer'][0][0];
        if ($up === 'A') {
            $s['phase'] = 'insurance';
        } elseif ($this->rules['peek'] && $this->points($up) === 10 && $this->dealerBlackjack($s)) {
            return $this->finish($s);
        }

        return $this->next($s);
    }

    /** Insurance (or even money, for a blackjack) asked of `$box`: true takes it. */
    public function insure(array $s, int $box, bool $take): array
    {
        if ($s['phase'] !== 'insurance' || ! isset($s['boxes'][$box]) || $s['boxes'][$box]['asked']) {
            throw new RuntimeException('No insurance to decide');
        }
        $s['boxes'][$box]['asked'] = true;
        if ($take) {
            if ($this->isBlackjack($s['boxes'][$box]['hands'][0])) {
                $s['boxes'][$box]['evenmoney'] = true;
            } else {
                $s['boxes'][$box]['insurance'] = intdiv($s['boxes'][$box]['bet'], 2);
            }
        }
        if (array_filter($s['boxes'], fn ($b) => ! $b['asked']) !== []) {
            return $s;
        }
        $s['phase'] = 'play';
        if ($this->rules['peek'] && $this->dealerBlackjack($s)) {
            return $this->finish($s);
        }

        return $this->next($s);
    }

    /** @return list<string> what the active hand may do */
    public function actions(array $s): array
    {
        return $s['phase'] === 'play' && $s['active'] !== null ? $this->actionsFor($s, ...$s['active']) : [];
    }

    /** @return list<string> what a hand could do on its turn (the box format lists it for every hand) */
    public function actionsFor(array $s, int $box, int $h, bool $ignoreDone = false): array
    {
        $hand = $s['boxes'][$box]['hands'][$h];
        if (! $ignoreDone && ($hand['done'] || $s['phase'] === 'over')) {
            return [];
        }
        $out = [];
        if (count($hand['cards']) === 2 && ! $hand['doubled']) {
            if ($this->canSplit($s, $box, $hand)) {
                $out[] = 'split';
            }
            [$total, $soft] = $this->value($hand['cards']);
            if (($this->rules['das'] || ! $hand['split']) && ($this->rules['double'] === [] || (! $soft && in_array($total, $this->rules['double'], true)))) {
                $out[] = 'double';
            }
        }

        return [...$out, 'hit', 'stand'];
    }

    public function act(array $s, string $action): array
    {
        if (! in_array($action, $this->actions($s), true)) {
            throw new RuntimeException("'{$action}' is not open now");
        }
        [$box, $h] = $s['active'];
        $hand = $s['boxes'][$box]['hands'][$h];
        if ($action === 'split') {
            $aces = $hand['cards'][0][0] === 'A';
            $first = ['cards' => [$hand['cards'][0], $this->draw($s)], 'split' => true, 'aces' => $aces] + $hand;
            $second = ['cards' => [$hand['cards'][1]], 'bet' => $hand['bet'], 'doubled' => false, 'split' => true, 'aces' => $aces, 'done' => false];
            if ($aces && $this->rules['splitAcesOne']) {   // one card each, no more
                $second['cards'][] = $this->draw($s);
                $first['done'] = $second['done'] = true;
            } else {
                $first['done'] = $this->value($first['cards'])[0] >= 21;
            }
            array_splice($s['boxes'][$box]['hands'], $h, 1, [$first, $second]);

            return $this->next($s);
        }
        if ($action === 'stand') {
            $hand['done'] = true;
        } else {   // hit, double: a card
            $hand['cards'][] = $this->draw($s);
            $hand['doubled'] = $action === 'double';
            $hand['done'] = $action === 'double' || $this->value($hand['cards'])[0] >= 21;
        }
        $s['boxes'][$box]['hands'][$h] = $hand;

        return $this->next($s);
    }

    /** Move to the next hand to play, or play the dealer and settle. */
    private function next(array $s): array
    {
        if ($s['phase'] === 'insurance') {
            return $s;
        }
        foreach ($s['boxes'] as $box => $b) {
            foreach ($b['hands'] as $h => $hand) {
                if (! $hand['done']) {
                    if (count($hand['cards']) === 1) {   // a split hand's second card comes when it is its turn
                        $s['boxes'][$box]['hands'][$h]['cards'][] = $this->draw($s);
                        if ($this->value($s['boxes'][$box]['hands'][$h]['cards'])[0] >= 21) {
                            $s['boxes'][$box]['hands'][$h]['done'] = true;

                            return $this->next($s);
                        }
                    }
                    $s['active'] = [$box, $h];

                    return $s;
                }
            }
        }

        return $this->finish($s);
    }

    /** The dealer plays out, and every hand's outcome and return is set. */
    private function finish(array $s): array
    {
        $s['active'] = null;
        $s['phase'] = 'over';
        if ($s['hole'] !== null) {
            $s['dealer'][] = $s['hole'];
            $s['hole'] = null;
        } elseif (count($s['dealer']) === 1) {
            $s['dealer'][] = $this->draw($s);
        }
        [$bust, $bj, $all] = [0, 0, 0];
        foreach ($s['boxes'] as $b) {
            foreach ($b['hands'] as $hand) {
                $all++;
                $bust += (int) ($this->value($hand['cards'])[0] > 21);
                $bj += (int) $this->isBlackjack($hand);
            }
        }
        $live = $bust < $all && $bj < $all;   // the dealer plays unless all are bust, or all blackjacks
        $dealerBj = $this->isBlackjack(['cards' => $s['dealer'], 'split' => false]);
        while ($live && ! $dealerBj) {
            [$total, $soft] = $this->value($s['dealer']);
            if ($total > 17 || ($total === 17 && ! ($soft && $this->rules['h17']))) {
                break;
            }
            $s['dealer'][] = $this->draw($s);
        }
        [$dealer] = $this->value($s['dealer']);
        foreach ($s['boxes'] as $box => $b) {
            $s['boxes'][$box]['insurancewin'] = $dealerBj ? 3 * $b['insurance'] : 0;
            foreach ($b['hands'] as $h => $hand) {
                $stake = $hand['bet'] * ($hand['doubled'] ? 2 : 1);
                [$total] = $this->value($hand['cards']);
                $bj = $this->isBlackjack($hand);
                [$outcome, $return] = match (true) {
                    $b['evenmoney'] => ['evenmoney', 2 * $hand['bet']],
                    $total > 21 => ['bust', 0],
                    $bj && $dealerBj => ['push', $hand['bet']],
                    $bj => ['blackjack', intdiv(5 * $hand['bet'], 2)],
                    $dealerBj => ['lose', $this->rules['enhcLosesAll'] || $this->rules['peek'] ? 0 : $stake - $hand['bet']],
                    $dealer > 21 || $total > $dealer => ['win', 2 * $stake],
                    $total === $dealer => ['push', $stake],
                    default => ['lose', 0],
                };
                $s['boxes'][$box]['hands'][$h] += ['outcome' => $outcome, 'return' => $return];
            }
        }

        return $s;
    }

    /** Cents staked in the round so far: bets, doubles, splits, insurance. */
    public function staked(array $s): int
    {
        $sum = 0;
        foreach ($s['boxes'] as $b) {
            $sum += $b['insurance'];
            foreach ($b['hands'] as $hand) {
                $sum += $hand['bet'] * ($hand['doubled'] ? 2 : 1);
            }
        }

        return $sum;
    }

    /** Cents the settled round returns. */
    public function returned(array $s): int
    {
        $sum = 0;
        foreach ($s['boxes'] as $b) {
            $sum += $b['insurancewin'] ?? 0;
            foreach ($b['hands'] as $hand) {
                $sum += $hand['return'] ?? 0;
            }
        }

        return $sum;
    }

    private function canSplit(array $s, int $box, array $hand): bool
    {
        if ($hand['split'] && ! $this->rules['resplit']) {
            return false;
        }
        [$a, $b] = [$hand['cards'][0][0], $hand['cards'][1][0]];

        return $a === $b || ($this->rules['splitTens'] && $this->points($a) === 10 && $this->points($b) === 10);
    }

    private function dealerBlackjack(array $s): bool
    {
        return $s['hole'] !== null && $this->isBlackjack(['cards' => [$s['dealer'][0], $s['hole']], 'split' => false]);
    }

    /** @param  array{cards: list<string>, split?: bool}  $hand */
    public function isBlackjack(array $hand): bool
    {
        return count($hand['cards']) === 2 && empty($hand['split']) && $this->value($hand['cards'])[0] === 21;
    }

    /**
     * The best total and whether an ace still counts 11.
     *
     * @param  list<string>  $cards
     * @return array{0: int, 1: bool}
     */
    public function value(array $cards): array
    {
        $total = array_sum(array_map(fn (string $c) => $this->points($c[0]), $cards));
        $soft = str_contains(implode('', array_map(fn ($c) => $c[0], $cards)), 'A') && $total + 10 <= 21;

        return [$soft ? $total + 10 : $total, $soft];
    }

    private function points(string $rank): int
    {
        return min(10, strpos(self::RANKS, $rank) + 1);
    }

    private function draw(array &$s): string
    {
        return array_shift($s['shoe']) ?? throw new RuntimeException('The shoe is empty');
    }

    /** @return list<string> a shuffled shoe */
    public function shoe(): array
    {
        $shoe = [];
        for ($d = 0; $d < $this->rules['decks']; $d++) {
            foreach (str_split(self::RANKS) as $r) {
                foreach (str_split('SHDC') as $suit) {
                    $shoe[] = $r.$suit;
                }
            }
        }
        for ($i = count($shoe) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$shoe[$i], $shoe[$j]] = [$shoe[$j], $shoe[$i]];
        }

        return $shoe;
    }
}
