<?php

namespace App\Services\GamePlay\NetEnt;

/**
 * Per-game spin request details the servlet protocol does not announce —
 * captured from the real clients (a browser session on Betboy, the servlet
 * requests read off Chrome's network log). Without them the game server
 * rejects the spin.
 *
 * `fixed`: parameters that price the bet (a bonus bet, extra hotlines). The
 * outcome library is recorded with the value here, so a spin asking for any
 * other value is refused rather than charged the recorded price.
 */
final class NetEntQuirks
{
    /** @var array<string, array{params?: array<string, string>, fixed?: list<string>, betlines?: string, start?: string}> */
    private const array GAMES = [
        // Hotline: `bet.hotlines` 1–3 "Bonus Bet" rows, each raising the stake
        'hotline' => ['params' => ['bet.hotlines' => '1', 'bettingmode' => 'cash'], 'fixed' => ['bet.hotlines']],
        // Victorious Max: the player picks MAX or classic maths at start
        'victorious_max' => ['params' => ['bet.mathMode' => 'max', 'bettingmode' => 'coins'], 'fixed' => ['bet.mathMode']],
        // Koi Princess: the "Bonus Bet" toggle costs extra
        'koi_princess' => ['params' => ['feature_bet' => 'false', 'bettingmode' => 'coins'], 'fixed' => ['feature_bet']],
        // Street Fighter II: one bet line; the fighters are the player's choice
        'street_fighter_2' => ['params' => ['character' => 'RYU', 'opponent' => 'KEN', 'bettingmode' => 'cash'], 'betlines' => '0-0'],
        // Swipe & Roll: a round opens with `pickwintype` (it carries the bet and
        // takes the stake); `spin` then settles it
        'swipe_and_roll' => ['start' => 'pickwintype'],
    ];

    /**
     * Table games: served by our own engines (NetEnt\Table\…), not from
     * recorded rounds — the player's choices decide them.
     *
     * @var array<string, array{0: string, 1: string}> game id => [kind, variant]
     */
    private const array TABLES = [
        'french_roulette_html' => ['roulette', 'french'],
        'american_roulette_html' => ['roulette', 'american'],
        'roulette_touch' => ['roulette', 'touch'],
        'baccarat' => ['baccarat', 'punto-banco'],
        'jacks_or_better_multiple_hands' => ['videopoker', 'jacks-or-better'],
        'blackjack_html' => ['blackjack', 'box'],
        'blackjack_single_deck_touch' => ['blackjack', 'box'],
        'blackjack_touch' => ['blackjack', 'touch'],
    ];

    /** @return array{0: string, 1: string}|null [kind, variant] of a table game */
    public static function table(string $gameId): ?array
    {
        return self::TABLES[$gameId] ?? null;
    }

    /** The action that opens a round and takes the stake. */
    public static function startAction(string $gameId): string
    {
        return self::GAMES[$gameId]['start'] ?? 'spin';
    }

    /**
     * Whether a recorded round is a plain one — no feature: the start action
     * alone (`spin`), or the start action and its `spin` (Swipe & Roll).
     *
     * @param  list<array{sent: string, reply: string}>  $steps
     */
    public static function isPlain(string $gameId, array $steps): bool
    {
        $start = self::startAction($gameId);

        return array_column($steps, 'sent') === ($start === 'spin' ? ['spin'] : [$start, 'spin']);
    }

    /** @return array<string, string> extra parameters a spin sends */
    public static function spinParams(string $gameId): array
    {
        return self::GAMES[$gameId]['params'] ?? [];
    }

    /** @return array<string, string> spin parameters a player may not change, with their recorded values */
    public static function fixedParams(string $gameId): array
    {
        return array_intersect_key(self::spinParams($gameId), array_flip(self::GAMES[$gameId]['fixed'] ?? []));
    }

    /** The `bet.betlines` a spin sends, where the init's own value is not it. */
    public static function betLines(string $gameId): ?string
    {
        return self::GAMES[$gameId]['betlines'] ?? null;
    }

    /**
     * The coin value (cents) to record at: 1, or the smallest the game offers
     * when 1 is not among them (Serengeti Kings: 2,3,5,…).
     *
     * @param  array<string, string>  $init
     */
    public static function recordDenomination(array $init): int
    {
        $all = array_map('intval', explode(',', (string) ($init['denomination.all'] ?? '1')));

        $positive = array_filter($all, fn ($d) => $d > 0);

        return in_array(1, $all, true) || $positive === [] ? 1 : min($positive);
    }
}
