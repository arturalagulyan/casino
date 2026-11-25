<?php

namespace App\Services\GamePlay\Playtech;

use RuntimeException;

/**
 * How each Playtech game on Betboy spins — read off its client's own traffic
 * (the setup doesn't say): the bet at coin 1 (coin × this = the bet the
 * client sends), the lines it sends, a message it sends before every spin,
 * and the spin request when it isn't the platform's CasinoSpinRequest (40021).
 */
final class PlaytechGames
{
    /**
     * `feature`: the game's feature requests, tried when no spin type is answered —
     * `vary` a field taking one of several values (the player's pick), `$name`
     * a value from the last reply's data.
     *
     * @var array<string, array{bet: int, lines: int, before?: list<array<string, mixed>>, custom?: int, feature?: list<array<string, mixed>>}>
     */
    public const array SPEC = [
        'whk_pt' => ['bet' => 40, 'lines' => 40],                                      // White King
        'batbeg_pt' => ['bet' => 20, 'lines' => 20],                                   // Batman Begins
        'batjok_pt' => ['bet' => 25, 'lines' => 25,                                    // Batman & the Joker Jewels: free games for Batman's or the Joker's side
            'feature' => [['ID' => 48252, 'vary' => ['bonusType' => ['B', 'J']]]]],
        'batpen_pt' => ['bet' => 25, 'lines' => 0, 'custom' => 48385],                 // Batman & the Penguin Prize: {type, bet}
        'bvs_pt' => ['bet' => 25, 'lines' => 25,                                       // Batman v Superman Dawn of Justice: start the feature won, picks
            'feature' => [['ID' => 49104, 'featureName' => '$featureName'], ['ID' => 49102, 'vary' => ['pick' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]]]]],
        'glr2_pt' => ['bet' => 25, 'lines' => 25, 'before' => [['ID' => 49082]],      // Gladiator: Road to Rome (49082 "complete bonus" → 49080 before each spin)
            'feature' => [['ID' => 49087], ['ID' => 49084, 'vary' => ['pickIndex' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]]], ['ID' => 49082]]],
        'jla_pt' => ['bet' => 40, 'lines' => 40,                                       // Justice League: pick a hero (its position + 1)
            'feature' => [['ID' => 48992, 'vary' => ['value' => [1, 2, 3, 4, 5]]]]],
        'manos_pt' => ['bet' => 25, 'lines' => 25],                                    // Man of Steel
        'rbcp_pt' => ['bet' => 25, 'lines' => 25,                                      // Robocop: pick the bonus type, then picks
            'feature' => [['ID' => 48554, 'reply' => 48555, 'once' => true, 'vary' => ['pick' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]]],
                // some spins (nothing in the reply says so) open "select bonus type" first: 48552 {type} → 48553 {gameType, numFreegames}
                ['ID' => 48552, 'reply' => 48553, 'vary' => ['type' => [1, 2, 3, 5]]]]],
        'thedk_pt' => ['bet' => 20, 'lines' => 50,                                     // The Dark Knight: 50 lines, bet 20 × coin
            'feature' => [['ID' => 49091]]],
    ];

    /** The answers to a game's pre-spin message, for our server. */
    public const array BEFORE_REPLY = [
        49082 => [['data' => ['windowId' => null], 'ID' => 49080]],
    ];

    /** @return array{bet: int, lines: int, before?: list<array<string, mixed>>, custom?: int, feature?: list<array<string, mixed>>} */
    public static function spec(string $key): array
    {
        return self::SPEC[$key] ?? throw new RuntimeException("No spin spec for Playtech game {$key} — capture its client's spin request first");
    }
}
