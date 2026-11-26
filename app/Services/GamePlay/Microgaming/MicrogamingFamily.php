<?php

namespace App\Services\GamePlay\Microgaming;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What a Microgaming client family sends — so far the "TheForce" slots
 * (`verb="AdvSlot"`, `<Request verbex='…' …/>`):
 *
 *   Refresh                         the setup (and a round left in play)
 *   Spin chipSize numChips activePaylines offerId instanceId    a paid spin
 *   BonusPick item                  a pick (Thunderstruck II's Great Hall of Spins, …)
 *   FreeSpin                        the next free spin
 *
 * A reply asks for more while a `<Bonus … state="1">` waits for a pick or
 * `freeSpinsRemaining` > 0.
 */
class MicrogamingFamily
{
    /** @return array{0: string, 1: array<string, string>} */
    public static function refresh(bool $quantum = false): array
    {
        return ['Refresh', $quantum ? ['json' => '{"QuantumRequest":{"Request":"Refresh"}}'] : []];
    }

    /**
     * Quantum (Mahi Gaming): the feature the stack plays next, its bet block,
     * and the bet's cost per level × denomination.
     *
     * @param  array<string, mixed>  $q
     * @return array{next: string, type: string, lines: int, unit: int}
     */
    public static function quantumBet(array $q): array
    {
        $stack = (array) ($q['QuantumResponse']['Stack'] ?? []);
        $features = (array) ($stack['Features'] ?? []);
        $at = (int) (($stack['Next'] ?? [0])[0] ?? 0);
        $nextFeature = (array) ($features[$at] ?? []);
        foreach ($features as $f) {
            if ((int) ($f['Index'] ?? -1) === $at) {
                $nextFeature = (array) $f;
            }
        }
        $next = (string) ($nextFeature['Name'] ?? 'BaseGame');
        $bet = null;
        foreach ($features as $f) {
            foreach ((array) ($f['Blocks'] ?? []) as $b) {
                if (($b['Type'] ?? '') === 'SlotBet') {
                    $bet = $b;
                }
            }
        }
        // a spin costs lines × level × denomination (the Refresh's own `Wager` isn't it)
        $lines = (int) ($bet['Lines'] ?? 0);

        return ['next' => $next, 'type' => (string) ($nextFeature['Type'] ?? 'SlotGame'), 'lines' => $lines, 'unit' => max(1, $lines)];
    }

    /**
     * The paid spin at `$chip` cents a coin, one coin.
     *
     * @return array<string, string>
     */
    public static function spin(string $init, int $chip, int $coins = 1): array
    {
        if (($q = MicrogamingLiveClient::quantum($init)) !== null) {
            $bet = self::quantumBet($q);

            return ['json' => json_encode(['QuantumRequest' => ['Request' => 'Spin', 'Feature' => 'BaseGame', 'BetLevel' => $coins,
                'Denomination' => $chip, 'Wager' => $bet['unit'] * $coins * $chip, 'Lines' => $bet['lines']]])];
        }
        return ['chipSize' => (string) $chip, 'numChips' => (string) $coins,
            'activePaylines' => (string) (MicrogamingLiveClient::attr($init, 'Bet', 'activePaylines') ?? '0'), 'offerId' => '0', 'instanceId' => '0'];
    }

    /**
     * What the client sends after `$reply`, if the round goes on.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function next(string $reply, ?\Closure $chooser = null, int $choices = 0): ?array
    {
        if ($reply === '') {
            return null;
        }
        // Quantum: the feature stack names what comes next — anything but the base game is played on
        if (($q = MicrogamingLiveClient::quantum($reply)) !== null) {
            ['next' => $next, 'type' => $type] = self::quantumBet($q);
            if ($next === 'BaseGame') {
                return null;
            }
            // what the client sends for a feature of each type (a wheel: WheelSpin; free spins: FreeSpin)
            $verb = match ($type) {
                'WheelGame' => 'WheelSpin',
                'PickGame', 'BonusGame' => 'BonusPick',
                default => 'FreeSpin',
            };

            return [$verb, ['json' => json_encode(['QuantumRequest' => ['Request' => $verb, 'Feature' => $next]])]];
        }
        // a pick bonus starting (state 1) or under way (state 2: "pick X of Y" — more picks to make) — while
        // the game waits in a feature (Framework state 2). Idle (0) a game can carry a dormant one (Eagles
        // Wings' LuckyEagleBonus3 sits at state 1 in every Refresh); done (3) the picked bonus stays listed
        // (Monster Wheels: its pick's reply still shows `<Bonus state="1">`, next come the free spins)
        $framework = MicrogamingLiveClient::attr($reply, 'Framework', 'state');
        if (($framework === '2' || $framework === null) && preg_match('/<Bonus\b[^>]*\bstate="[12]"[^>]*>.*?(?:<\/Bonus>|$)/s', $reply, $bonus)) {
            // the items on offer (Thunderstruck II's Great Hall: those unlocked), less those already picked — a
            // random one, so the library holds rounds for every choice ({@see \App\Services\GamePlay\Protocol\MicrogamingProtocol} switches to them)
            $items = preg_match('/\bitems="([^"]*)"/', $bonus[0], $m) ? array_values(array_filter(explode(',', $m[1]), 'strlen')) : [];
            preg_match_all('/<Pick\b[^>]*\bitem="(\d+)"/', $bonus[0], $picked);
            $items = array_values(array_diff($items, $picked[1])) ?: $items;

            return ['BonusPick', ['item' => (string) ($chooser ? $chooser($reply) : ($items !== [] ? $items[array_rand($items)] : 0))]];
        }
        // free spins under way, or awarded and not started (`<Slot state="1">` — Monster Wheels after its pick)
        if ((int) (MicrogamingLiveClient::attr($reply, 'NextSpin', 'freeSpinsRemaining') ?? 0) > 0
            || MicrogamingLiveClient::attr($reply, 'Slot', 'state') === '1') {
            return ['FreeSpin', []];
        }
        // a feature waiting on a choice it doesn't list (King Tusk's three free-spins bonuses: BonusPick
        // item 0–2 — `$choices` is the game's `feature_choices`); a random one, as for listed picks
        if ($choices > 0 && $framework === '2') {
            return ['BonusPick', ['item' => (string) ($chooser ? $chooser($reply) : random_int(0, $choices - 1))]];
        }

        return null;
    }

    /**
     * The packet version the client puts on `<Pkt>`: TheForce clients read it
     * from their game config (`assets/config/<gameJsonHash>.json`); null —
     * the live client probes for it.
     *
     * @param  array<string, mixed>  $info
     */
    public static function packetVersion(string $html, array $info): ?int
    {
        if (preg_match('/"gameJsonHash":"([0-9a-f]+)"/', $html, $m)) {
            $cfg = Http::withUserAgent('Mozilla/5.0')->timeout(60)->retry(2, 1000, throw: false)
                ->get(rtrim((string) $info['gameUrl'], '/')."/assets/config/{$m[1]}.json")->json();
            if (is_array($cfg) && isset($cfg['packetVersion'])) {
                return (int) $cfg['packetVersion'];
            }
        }

        return null;   // probed: the first version the server answers
    }

    /** Stake of a spin, cents: chip × coins × the coin cost of the bet's paylines (every `Payline paylineCost`, all lines played). */
    public static function stake(string $init, int $chip, int $coins): int
    {
        if (($q = MicrogamingLiveClient::quantum($init)) !== null) {
            return self::quantumBet($q)['unit'] * $chip * $coins;
        }
        $bet = preg_match('/<Bet\b.*?<\/Bet>/s', $init, $b) ? $b[0] : $init;
        preg_match_all('/<Payline\b[^>]*\bpaylineCost="(\d+)"/', $bet, $m);
        $cost = array_sum(array_map('intval', $m[1]));
        if ($cost <= 0) {   // no per-line costs (Fortunium): a coin per paid line — the active ones less the free ones
            $lines = array_filter(explode(',', (string) MicrogamingLiveClient::attr($init, 'Bet', 'activePaylines')), 'strlen');
            $free = array_filter(explode(',', (string) MicrogamingLiveClient::attr($init, 'NextSpin', 'nextActiveFreePaylines')), 'strlen');
            $cost = count($lines) - count($free) ?: (int) (MicrogamingLiveClient::attr($init, 'Bet', 'numPaylinesPerGame') ?? 0);
        }
        if ($cost <= 0) {
            throw new RuntimeException('The Refresh reply names no payline cost');
        }

        return $chip * $coins * $cost;
    }

    /** @return list<int> chip sizes, cents */
    public static function chips(string $init): array
    {
        if (($q = MicrogamingLiveClient::quantum($init)) !== null) {
            $chips = array_map('intval', (array) ($q['Settings']['Denom']['Values'] ?? []));
            sort($chips);

            return $chips;
        }
        $chips = array_map('intval', array_filter(explode(',', (string) MicrogamingLiveClient::attr($init, 'Bet', 'validChips'))));
        sort($chips);

        return $chips;
    }
}
