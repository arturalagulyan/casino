<?php

namespace App\Services\GamePlay\Amatic;

use App\Services\GamePlay\Protocol\AmaticWildcatHandler;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Template maths for an Amatic WildCat Gaming title, read from its engine
 * (the client ships the paytable for its help pages; the server keeps its
 * reels to itself, so the strips are ours, built from symbol weights and
 * gated by the shop's RTP like every other cascade game).
 *
 * Supported family: "tumble" — pay-anywhere counts per symbol
 * (`this.<counts>[sym]=[8,10,12]` + `this.<pays>[sym]=[…]` in the game
 * class), wild, multiplier symbols, bonus symbols (AnubisGold).
 * Played by {@see AmaticWildcatHandler}.
 */
class AmaticWildcatMath
{
    /** Symbol weights per strip, by role (pays rank from the top). */
    private const array WEIGHTS = [
        'pay' => [12, 16, 20, 24, 32, 36, 40, 44, 48, 52, 56, 60],
        'scatter' => 6,
        'wild' => 3,
        'multiplier' => 1,
        'multiplier_free' => 4,
    ];

    /** Multiplier values the help lists, weighted toward the small ones. */
    private const array MULTIPLIER_WEIGHTS = [
        2 => 120, 3 => 75, 4 => 54, 5 => 42, 6 => 30, 8 => 24, 10 => 21, 12 => 15,
        15 => 12, 20 => 9, 25 => 6, 50 => 3, 100 => 2, 250 => 1, 500 => 1,
    ];

    /** Hold & Win base-game coin values (× bet) — as often as on Amatic's live server. */
    private const array BASE_VALUE_WEIGHTS = [1 => 570, 2 => 330, 5 => 80, 10 => 20, 15 => 5];

    /** Hold & Win bonus coin values (× bet; cash pots by their multiple) — as seen on Amatic's live server. */
    /**
     * WildCat line slots and the rules their help text states (the client
     * ships pays, lines and strips; these it only describes).
     * Played by AmaticWildcatLinesHandler.
     */
    private const array LINE_GAMES = [
        // book 10 = scatter + substitute, stacked wild 11, gem 9 (bonus only, reels 2–4)
        'MerlinsBookOfDragons' => [
            'tail' => 'merlin', 'feature' => 'book_gems', 'scatter' => 10, 'substitutes' => [10, 11], 'trigger' => 3,
            'free_spins' => [3 => 10, 4 => 15, 5 => 20], 'gem' => 9, 'gem_reels' => [1, 2, 3], 'gem_every' => 120,
            'expanding' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
            // buy: 10 spins · 15 + a gem · 20 + a gem · 20 starting with 1–3 gems active
            'buy_options' => [['scatters' => 3], ['scatters' => 4, 'gem' => 1], ['scatters' => 5, 'gem' => 1], ['scatters' => 5, 'start_gems' => [1, 3]]],
        ],
        // 10 lines, wild 13, bonus 12; coins 18 (cash pots 14–17), collectors 19 on reels 1 and 5, ADD 20,
        // MULTIPLY 21 (value tables from the client, coin/add values in tenths of the bet); 3+ bonus symbols:
        // pick bonus spins (no A/K/Q/J) or hyperspins (only coins + collectors)
        'CleoLegendsOfEgypt' => [
            'tail' => 'cleo', 'feature' => 'coins', 'scatter' => 12, 'substitutes' => [13], 'trigger' => 3, 'free_spins' => [3 => 8, 4 => 12, 5 => 16],
            'coin' => 18, 'collector' => 19, 'add' => 20, 'multiply' => 21, 'empty' => 22, 'collector_reels' => [0, 4],
            'jackpots' => [17 => 25, 16 => 50, 15 => 250, 14 => 1000],
            'coin_values' => [10 => 1850, 20 => 1950, 30 => 2050, 50 => 570, 100 => 150, 1000 => 30],
            'add_values' => [10 => 50, 15 => 25, 20 => 10, 50 => 5, 100 => 1],
            'multiply_values' => [2 => 100, 3 => 50, 4 => 25, 5 => 5, 10 => 1],
            'choices' => [['spins' => [3 => 8, 4 => 12, 5 => 16]], ['spins' => [3 => 4, 4 => 6, 5 => 8], 'mode' => 'hyper']],
            'low' => [8, 9, 10, 11],
            // cash pots stay pots this often (Amatic's server rarely lets one meet a collector)
            'pot_keep' => 0.01, 'bonus_pot_keep' => 0.13,
            'buy_options' => [['scatters' => 3], ['scatters' => 4], ['scatters' => 5]],
        ],
        // 1024 ways from 2 of a kind, wild 12 on reels 2–5 collects every fish (4–6) value (client's
        // value/weight table, tenths of the bet); 3+ bonus 11 → 10/15/20 spins, every 3rd wild in them
        // adds 10 spins at x2 / x3 / x10. The live server knows it as EpicCatchFishing2.
        'EpicCatchFishing' => [
            'engine_id' => 'EpicCatchFishing2',
            'tail' => 'epic', 'feature' => 'fish', 'evaluation' => 'ways', 'ways_unit' => 10, 'wire_lines' => 10, 'rows' => 4,
            'scatter' => 11, 'substitutes' => [12], 'wild' => 12, 'trigger' => 3, 'free_spins' => [3 => 10, 4 => 15, 5 => 20],
            'fish' => [4, 5, 6], 'ways_group' => [4, 5, 6],   // any mix of fish pays
            'fish_values' => [10 => 1833, 20 => 1637, 30 => 2240, 40 => 2023, 50 => 1217, 75 => 680, 100 => 217, 125 => 100, 150 => 23, 200 => 8, 250 => 8, 500 => 7, 1000 => 7],
            'wild_levels' => [2, 3, 10], 'level_spins' => 10, 'low' => [11], 'bet_factor' => 10,
            // wild landing rates per cell as seen live (the strips have none)
            'wild_reels' => [1, 2, 3, 4], 'wild_rate' => 0.0032, 'bonus_wild_rate' => 0.012,
            'buy_options' => [['scatters' => 3], ['scatters' => 4], ['scatters' => 5]],
        ],
        // 10 lines, wild 11, bonus 10 (3/4/5 → 8/10/15 spins); coins 14 (values × bet from the client's
        // tables), collectors 15 on reels 1 and 5 bank every coin; collectors on both and no coin → a
        // respin of reels 2–4; 6+ coins → a 5×5 Hold & Win (also inside the bonus spins); any coin may
        // start the CASHPOT Hold & Win (a cash pot guaranteed). The init's strips are display-only:
        // the live server's board frequencies (400 captured spins) make the strips instead.
        'PiggyPots' => [
            'tail' => 'piggy', 'feature' => 'piggy', 'scatter' => 10, 'substitutes' => [11], 'wild' => 11, 'trigger' => 3,
            'free_spins' => [3 => 8, 4 => 10, 5 => 15], 'coin' => 14, 'collector' => 15, 'collector_reels' => [0, 4],
            'hw_trigger' => 6, 'hw_coin' => 0.065, 'cashpot_hw_coin' => 0.045, 'hw_collector' => 0.004, 'respin_min_coins' => 1,
            // of the bonus picks (gated by the win chances): Hold & Win · bonus spins · CASHPOT
            'bonus_mix' => ['holdwin' => 80, 'spins' => 12, 'cashpot' => 8],
            'live_counts' => [
                [0 => 61, 1 => 59, 2 => 108, 3 => 96, 4 => 113, 5 => 127, 6 => 130, 7 => 130, 8 => 119, 9 => 132, 10 => 4, 11 => 73, 14 => 41, 15 => 7],
                [0 => 77, 1 => 91, 2 => 157, 3 => 167, 4 => 83, 5 => 80, 6 => 120, 7 => 103, 8 => 104, 9 => 99, 10 => 12, 11 => 65, 14 => 42],
                [0 => 136, 1 => 144, 2 => 151, 3 => 148, 4 => 104, 5 => 91, 6 => 72, 7 => 74, 8 => 86, 9 => 82, 10 => 13, 11 => 35, 14 => 64],
                [0 => 111, 1 => 126, 2 => 120, 3 => 147, 4 => 108, 5 => 98, 6 => 66, 7 => 59, 8 => 141, 9 => 121, 10 => 14, 11 => 23, 14 => 66],
                [0 => 148, 1 => 139, 2 => 173, 3 => 154, 4 => 113, 5 => 95, 6 => 67, 7 => 76, 8 => 84, 9 => 60, 11 => 8, 14 => 71, 15 => 12],
            ],
            // coins land in stacks (live: 6+ coins on 1 board in 200); bonus spins: more coins and collectors
            'stacked' => [14 => 4], 'bonus_boost' => [14 => 6.8, 15 => 2.0],
            'bet_factor' => 10, 'booster_boost' => 2.0,
            // buy: Hold & Win · 8–15 bonus spins · CASHPOT Hold & Win
            'buy_options' => [['holdwin' => true], ['scatters' => [3, 5]], ['holdwin' => true, 'cashpot' => true]],
        ],
        // 5×7 tumbling pay-anywhere (8 / 10 / 12+ of a symbol, wilds 13 joining; pays in total bet / 10),
        // a 6th reel of multipliers (the bottom one multiplies a winning step; they move down a row per win),
        // rockets 16 / candy bombs 17 clearing paths / 3×3 once the tumbles stop. Bonus 14: 3/4/5 → pick
        // 8/12/16 FREE DROPS (2+ bonus symbols: +3) or 2/3/4 MEGA DROPS (values × bet drop onto the reels,
        // the first full reel pays its sum). Step wins validated on 3,891 live frames.
        'SweetCandyMegaDrops' => [
            'mechanic' => 'sweetcandy', 'evaluation' => 'ways', 'rows' => 7, 'scatter' => 14, 'substitutes' => [13], 'wild' => 13,
            'rocket' => 16, 'bomb' => 17, 'trigger' => 3, 'free_spins' => [3 => 8, 4 => 12, 5 => 16], 'mega_rounds' => [3 => 2, 4 => 3, 5 => 4],
            'retrigger' => 2, 'retrigger_spins' => 3, 'tiers' => [8, 10, 12], 'pay_unit' => 10, 'bet_factor' => 10, 'booster_boost' => 2.0,
            'multiplier_rate' => 0.41, 'multiplier_values' => [2 => 2388, 3 => 993, 4 => 959, 5 => 773, 6 => 724, 8 => 446, 10 => 231, 12 => 201,
                15 => 106, 20 => 73, 25 => 18, 50 => 25, 100 => 11],
            // rockets fly mostly straight (live: left 24, up 64, right 9, down 72, diagonals 2)
            'rocket_dirs' => [0 => 24, 1 => 1, 2 => 64, 3 => 1, 4 => 9, 5 => 1, 6 => 72, 7 => 1],
            'mega_values' => [1 => 16, 2 => 48, 3 => 59, 5 => 58, 8 => 27, 10 => 19, 50 => 6, 100 => 1.5, 500 => 0.15], 'mega_drop_rate' => 0.6,
            // free drops: the multiplier reel much fuller (live bonus boards: ≈5 of 7 positions)
            'bonus_multiplier_rate' => 0.7,
            'live_counts' => [
                [1 => 430, 2 => 1232, 3 => 903, 4 => 340, 5 => 1849, 6 => 1629, 7 => 697, 8 => 2114, 9 => 1922, 10 => 804, 11 => 2114, 12 => 2120, 13 => 436, 14 => 203, 16 => 19, 17 => 37],
                [1 => 943, 2 => 1589, 3 => 171, 4 => 967, 5 => 2459, 6 => 393, 7 => 1521, 8 => 2388, 9 => 489, 10 => 2103, 11 => 2708, 12 => 418, 13 => 430, 14 => 216, 16 => 28, 17 => 26],
                [1 => 1256, 2 => 494, 3 => 1035, 4 => 1150, 5 => 847, 6 => 1662, 7 => 2067, 8 => 810, 9 => 1882, 10 => 2432, 11 => 811, 12 => 1766, 13 => 402, 14 => 205, 16 => 22, 17 => 8],
                [1 => 363, 2 => 1232, 3 => 924, 4 => 335, 5 => 1897, 6 => 1669, 7 => 836, 8 => 1815, 9 => 2299, 10 => 809, 11 => 2067, 12 => 2018, 13 => 418, 14 => 120, 16 => 18, 17 => 29],
                [1 => 1489, 2 => 234, 3 => 814, 4 => 1656, 5 => 495, 6 => 1371, 7 => 2630, 8 => 345, 9 => 1907, 10 => 3098, 11 => 493, 12 => 1760, 13 => 383, 14 => 110, 16 => 35, 17 => 29],
            ],
            'stacked' => [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3, 10 => 3, 11 => 3, 12 => 3],
            'bonus_boost' => [14 => 1.0, 13 => 2.0], 'win_shape' => 2.0, 'win_chances' => [4, 130],
            // BONUS HUNT (25× stake, extra bet index 1): a 1 in 4 chance of the bonus
            'hunt_chance' => 0.25,
            // buy: 3 / 4 / 5 bonus symbols, then the pick
            'buy_options' => [['scatters' => 3], ['scatters' => 4], ['scatters' => 5]],
        ],
        // 6×6 tumbling ways (pay × ways in total bet / 20), wild 4 on reels 2–6; coins 0 (reels 2–6) pay their
        // value × bet; multipliers 1 add up and multiply the step they stand in; jumbo 2×2 / 3×3 blocks; bonus 3
        // stays and falls, 3–6 → 10/15/20/25 spins. Validated on 593 live frames (wins) / 152 tumbles.
        'Dinopups' => [
            'mechanic' => 'dinopups', 'evaluation' => 'ways', 'rows' => 6, 'scatter' => 3, 'substitutes' => [4], 'wild' => 4, 'coin' => 0,
            'multiplier' => 1, 'trigger' => 3, 'free_spins' => [3 => 10, 4 => 15, 5 => 20, 6 => 25], 'bet_factor' => 20, 'ways_unit' => 20,
            'coin_values' => [1 => 31, 2 => 12, 3 => 12, 4 => 7, 5 => 11, 7 => 2, 10 => 1.5, 12 => 1, 15 => 1, 18 => 0.5, 20 => 0.5, 25 => 0.4, 50 => 0.2,
                75 => 0.1, 100 => 0.06, 250 => 0.02, 500 => 0.01, 1000 => 0.005],
            'multiplier_values' => [2 => 46, 3 => 5, 4 => 2, 5 => 1],
            // jumbo blocks per fresh board (live: ≈0.67 2×2 and 0.06 3×3), mostly low symbols
            'jumbo_rates' => [2 => 0.67, 3 => 0.06], 'jumbo_symbols' => [5 => 3, 6 => 9, 7 => 11, 8 => 5, 9 => 16, 10 => 42, 11 => 47, 12 => 42, 13 => 39, 14 => 41],
            'live_counts' => [
                [1 => 9, 3 => 11, 5 => 85, 6 => 131, 7 => 153, 8 => 187, 9 => 231, 10 => 286, 11 => 322, 12 => 296, 13 => 362, 14 => 321],
                [0 => 12, 1 => 11, 3 => 8, 4 => 3, 5 => 74, 6 => 134, 7 => 155, 8 => 159, 9 => 180, 10 => 271, 11 => 336, 12 => 278, 13 => 358, 14 => 281],
                [0 => 10, 1 => 8, 3 => 7, 4 => 7, 5 => 87, 6 => 117, 7 => 122, 8 => 149, 9 => 201, 10 => 236, 11 => 345, 12 => 282, 13 => 260, 14 => 267],
                [0 => 9, 1 => 6, 3 => 11, 4 => 4, 5 => 79, 6 => 95, 7 => 115, 8 => 142, 9 => 208, 10 => 247, 11 => 321, 12 => 256, 13 => 294, 14 => 276],
                [0 => 12, 1 => 5, 3 => 9, 4 => 10, 5 => 70, 6 => 116, 7 => 121, 8 => 125, 9 => 203, 10 => 232, 11 => 273, 12 => 291, 13 => 318, 14 => 318],
                [0 => 4, 1 => 7, 3 => 6, 4 => 6, 5 => 72, 6 => 111, 7 => 156, 8 => 143, 9 => 217, 10 => 265, 11 => 279, 12 => 294, 13 => 373, 14 => 294],
            ],
            // bonus spins: no bonus symbols or multipliers, coins much more often (live bonus boards)
            'bonus_boost' => [3 => 0, 1 => 0, 0 => 14.0],
            // base wins as small as live ones: a winning board is taken up to a target drawn with this mean (× bet)
            'win_shape' => 2.0,
            'stacked' => [5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 2, 11 => 2, 12 => 2, 13 => 2, 14 => 2],
            // buy: 10 / 15 / 20 / 25 bonus spins
            'buy_options' => [['scatters' => 3], ['scatters' => 4], ['scatters' => 5], ['scatters' => 6]],
        ],
        // 6×4, 40 lines (line unit = total bet / 20). Wilds 14 nudge down a row every spin; each reel's
        // light counts the wilds landing on it, the 3rd makes the reel sticky-wild for 3 spins; all of it
        // kept per bet level. 3–6 bonus 13 → 10/15/20/25 spins: wilds land ×2 (15) and gain ×1 per nudge
        // (to ×5, 18), a sticky reel stays for the rest of the bonus; the ×2+ wilds on a line add up.
        // Init strips are display-only: live board frequencies (400 spins / 4 bonuses).
        'WildRush' => [
            'mechanic' => 'wildrush', 'rows' => 4, 'scatter' => 13, 'substitutes' => [14, 15, 16, 17, 18], 'top' => 1, 'trigger' => 3,
            'free_spins' => [3 => 10, 4 => 15, 5 => 20, 6 => 25], 'wild' => 14, 'bonus_wild' => 15, 'wild_max' => 18,
            'sticky_after' => 3, 'sticky_spins' => 3, 'bet_factor' => 20, 'booster_boost' => 2.0, 'win_chances' => [4, 130],
            'live_counts' => [
                [1 => 113, 2 => 83, 3 => 117, 4 => 95, 5 => 123, 6 => 139, 7 => 137, 8 => 128, 9 => 137, 10 => 156, 11 => 164, 12 => 136, 13 => 16, 14 => 7],
                [1 => 77, 2 => 76, 3 => 73, 4 => 74, 5 => 130, 6 => 145, 7 => 132, 8 => 133, 9 => 173, 10 => 179, 11 => 163, 12 => 157, 13 => 16, 14 => 9],
                [1 => 102, 2 => 103, 3 => 87, 4 => 118, 5 => 134, 6 => 126, 7 => 131, 8 => 135, 9 => 137, 10 => 157, 11 => 147, 12 => 159, 13 => 9, 14 => 6],
                [1 => 79, 2 => 87, 3 => 67, 4 => 86, 5 => 109, 6 => 102, 7 => 100, 8 => 88, 9 => 196, 10 => 208, 11 => 223, 12 => 216, 13 => 20, 14 => 2],
                [1 => 97, 2 => 88, 3 => 91, 4 => 112, 5 => 149, 6 => 131, 7 => 148, 8 => 137, 9 => 155, 10 => 151, 11 => 159, 12 => 150, 13 => 16, 14 => 2],
                [1 => 58, 2 => 85, 3 => 50, 4 => 57, 5 => 108, 6 => 107, 7 => 110, 8 => 120, 9 => 238, 10 => 227, 11 => 185, 12 => 235, 13 => 14, 14 => 2],
            ],
            // the characters (1–4) come in tall stacks, the rest in short runs
            'stacked' => [1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3, 10 => 3, 11 => 3, 12 => 3],
            // bonus spins: no bonus symbols; wilds land on reels 1–3 (as in live bonuses), barely on 4–6
            'bonus_boost' => [13 => 0, 14 => [10.0, 10.0, 10.0, 0, 0, 0]],
            // the share of those wilds a bonus of this many spins keeps (prices 100 / 200 / 300 / 500 × bet)
            'bonus_wild_scale' => [10 => 0.93, 15 => 0.88, 20 => 0.76, 25 => 0.75],
            // buy: 10 / 15 / 20 / 25 bonus spins
            'buy_options' => [['scatters' => 3], ['scatters' => 4], ['scatters' => 5], ['scatters' => 6]],
        ],
        // 20 lines; wilds (super 13 on reels 1–3, extreme 14 / insane 15 on reel 5) fill per-bet meters
        // (5–30 / 20–100 / 50–125); a full meter, 3 bonus 16 + an extreme / insane wild on reel 5, or
        // (super) chance starts that bonus: 1 / 10 / 10 spins on a 5×5 board, 40 lines, the meter's
        // wilds scattered over them (same cell: ×2…×5, multipliers on a line multiply, max ×5) and
        // cash pots 12–9 (50 / 100 / 250 / 1000 × bet). Init strips are display-only: live frequencies.
        'PufferIsland' => [
            'mechanic' => 'puffer', 'tail' => 'puffer', 'rows' => 3, 'bonus_rows' => 5, 'scatter' => 16, 'substitutes' => [13, 14, 15],
            'top' => 1, 'trigger' => 3, 'free_spins' => [3 => 10], 'wilds' => [13, 14, 15],
            'meter_min' => [5, 20, 50], 'meter_max' => [30, 100, 125], 'bonus_spins' => [1, 10, 10], 'multiplier_max' => 5,
            'cashpots' => [12 => 50, 11 => 100, 10 => 250, 9 => 1000],
            'bonus_mix' => ['0' => 80, '1' => 14, '2' => 6], 'bet_factor' => 20, 'booster_boost' => 2.0, 'win_chances' => [4, 110],
            'live_counts' => [
                [1 => 160, 2 => 203, 3 => 181, 4 => 199, 5 => 118, 6 => 60, 7 => 105, 8 => 122, 13 => 5, 16 => 47],
                [1 => 212, 2 => 178, 3 => 147, 4 => 179, 5 => 67, 6 => 140, 7 => 149, 8 => 89, 13 => 7, 16 => 32],
                [1 => 159, 2 => 173, 3 => 216, 4 => 194, 5 => 118, 6 => 111, 7 => 80, 8 => 88, 13 => 10, 16 => 51],
                [1 => 129, 2 => 147, 3 => 133, 4 => 125, 5 => 167, 6 => 167, 7 => 154, 8 => 143, 16 => 35],
                [1 => 120, 2 => 119, 3 => 116, 4 => 133, 5 => 162, 6 => 167, 7 => 182, 8 => 157, 14 => 18, 15 => 26],
            ],
            'stacked' => [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3],
            // the bonus board: the base symbols without wilds / bonus symbols, plus the cash pots
            'bonus_boost' => [13 => 0, 14 => 0, 15 => 0, 16 => 0, 5 => 2.0, 6 => 2.0, 7 => 2.0, 8 => 2.0],
            'bonus_stacked' => [1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4],
            'cashpot_chance' => 0.001, 'cashpot_weights' => [12 => 60, 11 => 25, 10 => 12, 9 => 3],
            // buy: 1 super / 10 extreme / 10 insane wild bonus spins with this many wilds (priced 100 / 200 / 500)
            'buy_wilds' => [17, 83, 122],
            'buy_options' => [['variant' => 0], ['variant' => 1], ['variant' => 2]],
        ],
        // 1024 ways (pay units = total bet / 10), wild 9 on reels 2–4, golden shields 8 pay a pot,
        // 3+ bonus symbols 10 spin a wheel of (10–30 spins, x2–x10 locked multiplier)
        'RampageGoldenHits' => [
            'tail' => 'rampage', 'evaluation' => 'ways', 'ways_unit' => 10, 'wire_lines' => 10, 'rows' => 4,
            'scatter' => 10, 'substitutes' => [9], 'trigger' => 3, 'free_spins' => [3 => 10],
            'bonus_award' => 'wheel', 'wheel_spins' => [10, 12, 15, 20, 25, 30], 'wheel_multipliers' => [2, 3, 4, 5, 10],
            'pot' => ['symbol' => 8, 'pays' => [3 => 2, 4 => 5, 5 => 15, 6 => 50, 7 => 100, 8 => 500, 9 => 1000]],
            'bet_factor' => 1, 'init_tail' => 'short', 'bonus_extra' => [9, [1, 2, 3], 11],
            // buy: 10 · 20 · 30 bonus spins (10× bet a spin); the wheel picks the multiplier
            'buy_options' => [['spins' => 10], ['spins' => 20], ['spins' => 30]],
        ],
    ];

    /**
     * Per-game Hold & Win rules the client only states in its help text
     * (ElToro: the 1st / 2nd / 3rd multiplier collector adds x2 / x4 / x6).
     */
    private const array HOLDWIN_VARIANTS = [
        // buy: 1 joker · 1 multiplier joker · 1 multiplier joker + 2 more during the bonus
        'DoubleJokerHoldAndWin' => ['buy_options' => [[], ['multiplier' => true], ['multiplier' => true, 'extra_multi' => 2]]],
        // buy: 1 collector · 1 collector + 1 multiplier collector · 1 multiplier collector + 2 more during the bonus
        'ElToroFlamenco' => ['multiplier_steps' => [2, 4, 6], 'multiplier_max' => 12, 'respin_chance' => 0.5, 'coin_chance' => 0.03,
            'buy_options' => [[], ['start_collectors' => 2, 'multiplier' => true], ['multiplier' => true, 'extra_multi' => 2]]],
    ];

    private const array BONUS_VALUE_WEIGHTS = [1 => 180, 2 => 90, 5 => 290, 10 => 260, 15 => 160, 25 => 20, 50 => 10, 150 => 3, 1000 => 0.4];

    /**
     * @param  string  $init  Amatic's own init frame for the game (captured live)
     * @return array{attrs: array<string, mixed>, notes: list<string>}
     */
    public function build(string $gameId, string $js, string $init): array
    {
        // the game class's setup method: `<name>(){this.<a>=this.<b>="<GameId>"`
        if (! preg_match('/\w+\(\)\{this\.\w+=this\.\w+="'.preg_quote($gameId, '/').'"/', $js, $m, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("WildCat game class for {$gameId} not found in the engine");
        }
        $class = substr($js, $m[0][1], 6000);
        $symbols = $this->symbols($js);
        if ($this->lineRules($gameId) !== null) {
            return $this->linesGame($gameId, $js, $class, $symbols, $init);
        }
        if (in_array('S_COLLECTOR', $symbols, true) || in_array('SYMB_COLLECTOR', $symbols, true)) {
            return $this->holdWin($gameId, $js, $class, $symbols, $init);
        }
        [$counts, $pays] = $this->tables($class, $symbols);
        if ($pays === []) {
            throw new RuntimeException("Unsupported WildCat mechanic for {$gameId} (no pay-anywhere table)");
        }

        $byName = array_flip($symbols);
        $wild = $byName['WI1'] ?? null;
        $scatter = $byName['FS1'] ?? null;
        $bomb = $byName['MU1'] ?? null;
        // the base classes default it to 0; the game class sets the real one
        $reels = preg_match_all('/this\.YMd=(\d+)/', $js, $r) ? max(array_map('intval', $r[1])) : 6;
        $rows = preg_match('/this\.Yhg=(\d+)/', $class, $r) ? (int) $r[1] : 5;
        $coins = preg_match('/this\.YAF=(\d+)/', $class, $r) ? (int) $r[1] : 20;
        $tiers = array_values(array_filter(reset($counts) ?: [8, 10, 12], fn ($n) => $n > 0)) ?: [8, 10, 12];
        // `a.dgM=[10,15,20]` (bonus spins for trigger, trigger+1, …) and the multiplier values
        $freeSpins = preg_match('/a\.\w+=(\[\d+(?:,\d+){1,4}\]);a\.\w+=\[2,3,/', $js, $f) ? json_decode($f[1], true) : [10];
        $values = preg_match('/a\.\w+=\[\d+(?:,\d+){1,4}\];a\.\w+=(\[2,3,[\d,]+\])/', $js, $v) ? json_decode($v[1], true) : array_keys(self::MULTIPLIER_WEIGHTS);
        $tail = (new AmaticWildcatInit)->tail($init);
        $trigger = 4;

        $paytable = [];
        foreach ($symbols as $id => $name) {
            $paytable[$id] = array_map('floatval', array_slice(array_pad($pays[$id] ?? [], count($tiers), 0), 0, count($tiers)));
        }
        $ranked = array_keys(array_filter($paytable, fn ($row) => max($row) > 0));
        usort($ranked, fn ($a, $b) => max($paytable[$b]) <=> max($paytable[$a]));

        $weights = ['base' => [], 'free' => []];
        foreach ($ranked as $rank => $id) {
            $weights['base'][$id] = $weights['free'][$id] = self::WEIGHTS['pay'][min($rank, count(self::WEIGHTS['pay']) - 1)];
        }
        foreach (['scatter' => $scatter, 'wild' => $wild] as $role => $id) {
            if ($id !== null) {
                $weights['base'][$id] = $weights['free'][$id] = self::WEIGHTS[$role];
            }
        }
        if ($bomb !== null) {
            $weights['base'][$bomb] = self::WEIGHTS['multiplier'];
            $weights['free'][$bomb] = self::WEIGHTS['multiplier_free'];
        }
        $strips = [];
        foreach (['reelStrip' => 'base', 'reelStripBonus' => 'free'] as $prefix => $set) {
            for ($r = 0; $r < $reels; $r++) {
                $strips[$prefix.($r + 1)] = $this->strip($weights[$set], crc32($gameId.'|'.$prefix.'|'.$r));
            }
        }
        $pool = [];
        foreach ($values as $value) {
            $pool = array_merge($pool, array_fill(0, self::MULTIPLIER_WEIGHTS[(int) $value] ?? 1, (int) $value));
        }
        $table = [];
        foreach (array_values($freeSpins) as $k => $spins) {
            $table[$trigger + $k] = (int) $spins;
        }

        return [
            'attrs' => [
                'reel_count' => $reels,
                'row_count' => $rows,
                'symbols' => array_keys($symbols),
                'symbol_count' => count($symbols),
                'wild_symbol' => $wild,
                'scatter_symbol' => $scatter,
                'wild_multiplier' => 1,
                'min_match' => 3,
                'paytable' => $paytable,
                'paylines' => [],
                'reel_strips' => $strips,
                'has_bonus' => $scatter !== null,
                'has_free_spins' => $scatter !== null,
                'free_spins_count' => (int) ($table[$trigger] ?? 10),
                'free_spins_multiplier' => 1,
                'has_gamble' => false,
                'win_chances' => $this->winChances(3, 220),
                'default_bet_options' => array_map(fn ($b) => round($b * $coins / 100, 2), $tail['ladder']),
                'tumble_config' => [
                    'lines' => $coins,
                    'tiers' => $tiers,
                    'scatter_pays' => [],
                    'trigger' => $trigger,
                    'retrigger' => $trigger,
                    'retrigger_spins' => (int) ($table[$trigger] ?? 10),
                    'free_spins' => (int) ($table[$trigger] ?? 10),
                    'wild_symbol' => $wild,
                    'multiplier_symbol' => $bomb,
                    'multiplier_values' => $pool,
                    'multiplier_in_base' => $bomb !== null,
                    'multiplier_accumulate' => $bomb !== null,
                ],
                'bonus_config' => ['table' => [
                    'type' => 'wildcat',
                    'mechanic' => 'tumble',
                    'init' => $init,
                    'free_spins' => $table,
                    'buy' => $tail['buy'],
                ]],
            ],
            'notes' => [
                'WildCat tumble game: paytable from the client, reel strips are ours (Amatic keeps its own server-side)',
                'bonus hunt is not offered',
            ],
        ];
    }

    /**
     * "Hold & Win" (JadeEmperor family): 3-of-a-kind lines on Amatic's own
     * strips (shipped in the init), coins with per-spin values, collectors,
     * a respin bonus with cash-pot coins. Played by AmaticHoldWinHandler.
     *
     * @param  array<int, string>  $symbols
     * @return array{attrs: array<string, mixed>, notes: list<string>}
     */
    private function holdWin(string $gameId, string $js, string $class, array $symbols, string $init): array
    {
        $byName = array_flip($symbols);
        $find = fn (string $re) => array_values(array_filter(array_keys($byName), fn ($n) => preg_match($re, $n)));
        $wild = $byName[$find('/TOPAWARD|^S?Y?M?B?_?WILD/')[0] ?? ''] ?? 0;
        $collector = $byName[$find('/COLLECTOR$/')[0] ?? ''] ?? null;
        $empty = $byName[$find('/EMPTY$/')[0] ?? ''] ?? null;
        $coinNames = $find('/_COIN\d+$/');
        $coinIds = array_map(fn ($n) => $byName[$n], $coinNames);
        if ($collector === null || $empty === null || $coinIds === []) {
            throw new RuntimeException("Hold & Win symbols not found for {$gameId}");
        }

        // minified enum key → value, for `this.<pays>[a.<key>]=[n]`
        preg_match_all('/\w\[\w\.(\w+)=(\d+)\]="\w+"/', $js, $keys, PREG_SET_ORDER);
        $keyValue = [];
        foreach ($keys as [, $key, $value]) {
            $keyValue[$key] = (int) $value;
        }
        $pays = [];
        preg_match_all('/this\.(\w+)\[a\.(\w+)\]=\[(\d+)\]/', $class, $rows, PREG_SET_ORDER);
        foreach ($rows as [, , $key, $pay]) {
            if (isset($keyValue[$key])) {
                $pays[$keyValue[$key]] = (int) $pay;
            }
        }
        // the 1-based lines the class turns 0-based right after (`for(const b of this.<lines>)…--`)
        if (! preg_match('/this\.(\w+)=(\[\[\d(?:,\d)+\](?:,\[\d(?:,\d)+\])+\]);.{0,80}?for\(const \w+ of this\.\1\)/', $class, $l)) {
            throw new RuntimeException("Paylines not found for {$gameId}");
        }
        $lines = array_map(fn ($line) => array_map(fn ($row) => $row - 1, $line), json_decode($l[2], true));

        // client constants after the enum: the value table (cash pots as codes) and the cash-pot multiples
        $valueTable = preg_match('/a\.\w+=\[(1,2,5,10,15(?:,a\.\w+)+)\]/', $js, $v) ? $v[1] : '1,2,5,10,15';
        preg_match_all('/a\.(\w+)=(\d{3})[;,]/', $js, $codes, PREG_SET_ORDER);
        $codeOf = [];
        foreach ($codes as [, $key, $code]) {
            $codeOf['a.'.$key] = (int) $code;
        }
        $valueTable = array_map(fn ($x) => $codeOf[$x] ?? (int) $x, explode(',', $valueTable));
        $potMultiples = preg_match('/a\.\w+=\[(\d+),(\d+),(\d+),(1E3|\d+)\][;}]/', $js, $p)
            ? array_map(fn ($x) => (int) (float) $x, array_slice($p, 1)) : [25, 50, 150, 1000];
        $potCodes = array_values(array_filter($valueTable, fn ($x) => $x >= 100));
        $jackpots = count($potCodes) === count($potMultiples) ? array_combine($potCodes, $potMultiples) : [];

        $initFrame = new AmaticWildcatInit;
        // the init strips: numbers (DoubleJoker, tail v5), letters (ElToro, v8) or 2-hex (JadeEmperor, v2)
        $plausible = fn (array $s) => $s !== [] && min(array_map('count', $s)) > 30 && max(array_map('max', $s)) < count($symbols);
        $layout = 'v2';
        $width = 2;
        foreach (['v5' => 0, 'v8' => 1] as $candidate => $w) {
            if ($plausible($initFrame->strips($init, $w))) {
                [$layout, $width] = [$candidate, $w];
                break;
            }
        }
        $v5 = $layout !== 'v2';
        $strips = $initFrame->strips($init, $width);
        $tail = $initFrame->tail($init, $width);
        if ($layout === 'v8') {   // ElToro's server sends cash pots as their multiples
            $valueTable = array_map(fn ($x) => $jackpots[$x] ?? $x, $valueTable);
            $jackpots = array_combine(array_values($jackpots), array_values($jackpots));
        }
        $multi = $byName[$find('/COLLECTOR_MULTI$/')[0] ?? ''] ?? null;
        $variant = $v5 ? array_replace([
            'layout' => $layout, 'multi_collector' => $multi, 'multi_share' => 0.25, 'single_collect' => true,
            'bonus_cols' => 5, 'bonus_rows' => 4, 'bonus_coin_cols' => [0, 1, 3, 4], 'bonus_collector_col' => 2,
            'multiplier_steps' => [2], 'multiplier_max' => 6, 'multiplier_cells' => 4,
            // 16 coin cells: rarer landings (live bonuses run ~6–7 respins)
            'coin_chance' => 0.033, 'collector_chance' => 0.02,
        ], self::HOLDWIN_VARIANTS[$gameId] ?? []) : ['layout' => 'v2', 'buy_options' => [[], ['pot' => true]]];
        $collectorReel = 0;
        $coinReels = [];
        foreach ($strips as $r => $strip) {
            if (in_array($collector, $strip, true)) {
                $collectorReel = $r;
            }
            if (array_intersect($coinIds, $strip) !== []) {
                $coinReels[] = $r;
            }
        }
        $rowsCount = max(array_map(fn ($line) => max($line), $lines)) + 1;

        $paytable = [];
        foreach ($symbols as $id => $name) {
            $paytable[$id] = [0, 0, 0, (float) ($pays[$id] ?? 0)];
        }
        $bonusValues = [];
        foreach ($valueTable as $value) {
            $bonusValues[$value] = self::BONUS_VALUE_WEIGHTS[$jackpots[$value] ?? $value] ?? 1;
        }

        return [
            'attrs' => [
                'reel_count' => count($strips),
                'row_count' => $rowsCount,
                'symbols' => array_keys($symbols),
                'symbol_count' => count($symbols),
                'wild_symbol' => $wild,
                'scatter_symbol' => null,
                'wild_multiplier' => 1,
                'min_match' => 3,
                'paytable' => $paytable,
                'paylines' => $lines,
                'reel_strips' => array_combine(array_map(fn ($i) => 'reelStrip'.($i + 1), array_keys($strips)), $strips),
                'has_bonus' => true,
                'has_free_spins' => false,
                'free_spins_count' => 0,
                'free_spins_multiplier' => 1,
                'has_gamble' => false,
                'win_chances' => $this->winChances(4, 250),
                'default_bet_options' => array_map(fn ($b) => round($b * count($lines) / 100, 2), $tail['ladder']),
                'bonus_config' => ['table' => [
                    'type' => 'wildcat',
                    'mechanic' => 'holdwin',
                    'init' => $init,
                    'buy' => $tail['buy'],
                    'bet_factor' => count($lines),
                    'holdwin' => array_replace([
                        'strips' => $strips,
                        'rows' => $rowsCount,
                        'lines' => $lines,
                        'pays' => $pays,
                        'wild' => $wild,
                        'collector' => $collector,
                        'empty' => $empty,
                        'coin_first' => min($coinIds),
                        'coin_slots' => count($coinIds),
                        'collector_reel' => $collectorReel,
                        'coin_reels' => $coinReels,
                        'value_table' => $valueTable,
                        'jackpots' => $jackpots,
                        'base_values' => self::BASE_VALUE_WEIGHTS,
                        'bonus_values' => $bonusValues,
                        'coin_chance' => 0.095,
                        'collector_chance' => 0.03,
                        'respins' => 3,
                    ], $variant),
                ]],
            ],
            'notes' => [
                'WildCat Hold & Win: strips, lines and pays are Amatic\'s; coin values and respin odds are ours (calibrated on live play)',
                'bonus hunt is not offered',
            ],
        ];
    }

    /** The id Amatic's live server knows the game by (an operator config can rename it). */
    public static function engineId(string $gameId): string
    {
        return (string) (self::LINE_GAMES[$gameId]['engine_id'] ?? $gameId);
    }

    /** @return array<string, mixed>|null */
    private function lineRules(string $gameId): ?array
    {
        return self::LINE_GAMES[$gameId] ?? null;
    }

    /**
     * A WildCat line slot: Amatic's strips (init, one character per symbol),
     * the class's pays per count and lines, the rules from LINE_GAMES.
     *
     * @param  array<int, string>  $symbols
     * @return array{attrs: array<string, mixed>, notes: list<string>}
     */
    private function linesGame(string $gameId, string $js, string $class, array $symbols, string $init): array
    {
        $rules = (array) $this->lineRules($gameId);
        $initFrame = new AmaticWildcatInit;
        $strips = $initFrame->strips($init, 1);
        $tail = $initFrame->tail($init, 1, ($rules['init_tail'] ?? '') === 'short');
        // display-only init strips (PiggyPots): strips with the live boards' symbol frequencies
        // (stacked symbols land in runs; the bonus spins' strips scale some counts up)
        $liveBonus = null;
        if (isset($rules['live_counts'])) {
            $strips = $liveBonus = [];
            $stacked = array_map('intval', (array) ($rules['stacked'] ?? []));
            foreach ((array) $rules['live_counts'] as $r => $counts) {
                $strips[] = $this->countStrip((array) $counts, crc32($gameId.'|live|'.$r), $stacked);
                $boosted = (array) $counts + array_map('intval', (array) ($rules['bonus_add'] ?? []));
                foreach ((array) ($rules['bonus_boost'] ?? []) as $sym => $x) {
                    if (isset($boosted[$sym])) {
                        $boosted[$sym] = (int) round($boosted[$sym] * (float) (is_array($x) ? ($x[$r] ?? 0) : $x));   // a list: per reel
                    }
                }
                $boosted = array_filter($boosted);
                $liveBonus[] = $this->countStrip($boosted, crc32($gameId.'|bonus|'.$r), array_map('intval', (array) ($rules['bonus_stacked'] ?? $stacked)));
            }
            unset($rules['live_counts'], $rules['stacked'], $rules['bonus_boost'], $rules['bonus_add'], $rules['bonus_stacked']);
        }
        // PiggyPots' coin values (× bet): `a.<v>=[1,2,…,1E3];a.<w>=[…]` — base, Hold & Win and CASHPOT weights
        if (($rules['feature'] ?? '') === 'piggy') {
            if (! preg_match('/a\.\w+=\[(1,2,3[\dE,]+)\];a\.\w+=\[([\dE,]+)\];a\.\w+=\[([\dE,]+)\];a\.\w+=\[([\dE,]+)\]\}/', $js, $vt)) {
                throw new RuntimeException("Coin value tables not found for {$gameId}");
            }
            [$values, $base, $hold, $pot] = array_map(fn ($l) => array_map('floatval', explode(',', $l)), array_slice($vt, 1));
            $weights = fn (array $w, int $from = 0) => array_slice(array_combine(array_map('intval', $values), $w), $from, null, true);
            $rules += ['coin_values' => $weights($base), 'hw_values' => $weights($hold), 'cashpot_hw_values' => $weights($pot),
                'cashpot_values' => $weights($pot, count($values) - 4), 'cashpots' => array_slice(array_map('intval', $values), -4), 'booster_price' => (float) ($tail['hunt'][0] ?? 0)];
        }
        if ($strips === []) {
            throw new RuntimeException("No reel strips in {$gameId}'s init");
        }
        $reels = count($strips);

        // pays: the symbol-indexed table (`this.<pays>[a.<key>]=[…]`) whose rows hold a
        // pay per count 1..reels, or only the 3/4/5-of-a-kind pays (padded); the
        // keys map to symbols through the enum, unlisted symbols pay nothing (short rows: the top counts)
        preg_match_all('/\w\[\w\.(\w+)=(\d+)\]="\w+"/', $js, $keys, PREG_SET_ORDER);
        $keyValue = [];
        foreach ($keys as [, $key, $value]) {
            $keyValue[$key] ??= (int) $value;
        }
        preg_match_all('/this\.(\w+)\[a\.(\w+)\]=\[([\d.E,]+)\]/', $class, $rows, PREG_SET_ORDER);
        $groups = [];
        foreach ($rows as [, $prop, $key, $list]) {
            if (isset($keyValue[$key])) {
                $groups[$prop][$keyValue[$key]] = array_map(fn ($v) => (float) $v, explode(',', $list));
            }
        }
        $pays = [];
        foreach ($groups as $byId) {
            $width = count(reset($byId));
            if (count($byId) >= 6 && $width >= 3 && $width <= $reels && count($byId) > count($pays)) {
                $pays = [];
                foreach (array_keys($symbols) as $id) {
                    $pays[$id] = array_pad($byId[$id] ?? [], -$reels, 0.0);
                }
            }
        }
        // lines: the biggest list of row lists one entry per reel
        preg_match_all('/=(\[\[\d(?:,\d)+\](?:,\[\d(?:,\d)+\])+\])/', $class, $ls);
        // (a game with a taller bonus board — PufferIsland — has a second set for it)
        $lines = $bonusLines = [];
        foreach ($ls[1] as $raw) {
            $l = json_decode($raw, true);
            $tall = isset($rules['rows']) && max(array_map('max', $l)) >= (int) $rules['rows'];
            if (count($l[0]) === $reels && $tall && count($l) > count($bonusLines)) {
                $bonusLines = $l;
            } elseif (count($l[0]) === $reels && ! $tall && count($l) > count($lines)) {
                $lines = $l;
            }
        }
        if (isset($rules['bonus_rows'])) {
            $rules['bonus_lines'] = $bonusLines;
        }
        if (isset($rules['booster_boost'])) {
            $rules['booster_price'] ??= (float) ($tail['hunt'][0] ?? 0);
            if (isset($rules['hunt_chance'])) {
                $rules['hunt_price'] = (float) ($tail['hunt'][1] ?? 0);   // the BONUS HUNT (× stake)
            }
        }
        $ways = ($rules['evaluation'] ?? 'lines') === 'ways';
        if ($pays === [] || ($lines === [] && ! $ways)) {
            throw new RuntimeException("Pays or lines not found for {$gameId}");
        }
        $rowsCount = (int) ($rules['rows'] ?? max(array_map('max', $lines)) + 1);
        // the ladder is per line (Merlin) or the whole stake (Rampage)
        $betFactor = (int) ($rules['bet_factor'] ?? max(1, count($lines)));

        // bonus strips: the base ones, with the bonus-only symbol mixed into its reels
        $bonusStrips = $liveBonus ?? (isset($rules['low'])   // Cleo's bonus spins: the low symbols removed
            ? array_map(fn ($s) => array_values(array_filter($s, fn ($sym) => ! in_array($sym, (array) $rules['low'], true))), $strips)
            : $strips);
        // bonus-only symbols mixed into their reels: Merlin's gem; extra wilds for Rampage's richer bonus reels
        foreach ([[$rules['gem'] ?? null, $rules['gem_reels'] ?? [], $rules['gem_every'] ?? 0], (array) ($rules['bonus_extra'] ?? [null, [], 0])] as [$sym, $mixReels, $every]) {
            if ($sym === null || (int) $every <= 0) {
                continue;
            }
            foreach ((array) $mixReels as $r) {
                for ($i = (int) $every - 1; $i < count($bonusStrips[$r]); $i += (int) $every) {
                    $bonusStrips[$r][$i] = (int) $sym;
                }
            }
        }
        $paytable = [];
        foreach ($symbols as $id => $name) {
            $paytable[$id] = array_merge([0], array_map('floatval', $pays[$id] ?? array_fill(0, $reels, 0)));
        }

        return [
            'attrs' => [
                'reel_count' => $reels, 'row_count' => $rowsCount,
                'symbols' => array_keys($symbols), 'symbol_count' => count($symbols),
                'wild_symbol' => ((array) $rules['substitutes'])[count((array) $rules['substitutes']) - 1] ?? null,
                'scatter_symbol' => $rules['scatter'], 'wild_multiplier' => 1, 'min_match' => 2,
                'paytable' => $paytable, 'paylines' => $lines,
                'reel_strips' => array_combine(array_map(fn ($i) => 'reelStrip'.($i + 1), array_keys($strips)), $strips),
                'has_bonus' => true, 'has_free_spins' => true, 'free_spins_count' => (int) reset($rules['free_spins']),
                'free_spins_multiplier' => 1, 'has_gamble' => false,
                'win_chances' => $this->winChances(...array_map('intval', (array) ($rules['win_chances'] ?? [4, 180]))),
                'default_bet_options' => array_map(fn ($b) => round($b * $betFactor / 100, 2), $tail['ladder']),
                'bonus_config' => ['table' => [
                    'type' => 'wildcat', 'mechanic' => $rules['mechanic'] ?? 'lines', 'init' => $init, 'buy' => $tail['buy'], 'bet_factor' => $betFactor,
                    'lines' => $rules + [
                        'strips' => $strips, 'bonus_strips' => $bonusStrips, 'rows' => $rowsCount, 'lines' => $lines, 'symbol_count' => count($symbols),
                        'pays' => array_map(fn ($row) => array_map('floatval', $row), $pays),
                    ],
                ]],
            ],
            'notes' => [
                'WildCat line slot: strips, lines and pays are Amatic\'s; bonus-only symbol frequency is ours',
                'bonus hunt is not offered',
            ],
        ];
    }

    /** @return array<int, string> enum value → name, e.g. 0 => 'HV4' … 11 => 'MU1' */
    private function symbols(string $js): array
    {
        // `b[b.<key>=0]="HV4";…;b[b.<key>=12]="COUNT"` (the variable letter varies per game)
        if (! preg_match('/((\w)\[\2\.\w+=0\]="\w+";(?:\2\[\2\.\w+=\d+\]="\w+";)+)\2\[\2\.\w+=\d+\]="COUNT"/', $js, $m)) {
            throw new RuntimeException('WildCat symbol enum not found in the engine');
        }
        preg_match_all('/\w\[\w\.\w+=(\d+)\]="(\w+)"/', $m[1], $s, PREG_SET_ORDER);
        $out = [];
        foreach ($s as [, $id, $name]) {
            $out[(int) $id] = $name;
        }

        return $out;
    }

    /**
     * The game class's two symbol-indexed tables: the counts each tier needs
     * (equal for every paying symbol) and the pays per tier.
     *
     * @param  array<int, string>  $symbols
     * @return array{0: array<int, list<int>>, 1: array<int, list<float>>}
     */
    private function tables(string $class, array $symbols): array
    {
        preg_match_all('/this\.(\w+)\[a\.\w+\]=\[([\d.E,]+)\]/', $class, $rows, PREG_SET_ORDER);
        $groups = [];
        foreach ($rows as [, $prop, $list]) {
            $groups[$prop][] = array_map(fn ($v) => (float) $v, explode(',', $list));
        }
        $counts = $pays = [];
        foreach ($groups as $prop => $list) {
            if (count($list) !== count($symbols)) {
                continue;
            }
            // rows follow the enum order (a.<first>=0, …)
            $rowsBySymbol = array_combine(array_keys($symbols), $list);
            $paying = array_filter($rowsBySymbol, fn ($r) => max($r) > 0);
            $isCounts = count(array_unique(array_map('json_encode', $paying))) === 1;
            if ($isCounts) {
                $counts = array_map(fn ($r) => array_map('intval', $r), $rowsBySymbol);
            } else {
                $pays = $rowsBySymbol;
            }
        }

        return [$counts, $pays];
    }

    /**
     * @param  array<int, int>  $weights
     * @return list<int>
     */
    /**
     * A strip with the symbol frequencies of `$counts` (cells seen per symbol
     * over 3-row windows); `$stacked` symbols (=> longest run) come in runs.
     *
     * @param  array<int, int>  $counts
     * @param  array<int, int>  $stacked
     * @return list<int>
     */
    private function countStrip(array $counts, int $seed, array $stacked = []): array
    {
        $rng = new Randomizer(new Mt19937($seed));
        $length = array_sum($counts) / 3;
        $units = [];
        foreach ($counts as $sym => $n) {
            for ($left = max(1, (int) round($n * $length / array_sum($counts))); $left > 0; $left -= $run) {
                $run = min($left, isset($stacked[$sym]) ? $rng->getInt(1, $stacked[$sym]) : 1);
                $units[] = array_fill(0, $run, (int) $sym);
            }
        }

        return array_merge(...$rng->shuffleArray($units));
    }

    private function strip(array $weights, int $seed): array
    {
        $rng = new Randomizer(new Mt19937($seed));
        $bag = [];
        foreach ($weights as $sym => $n) {
            for ($i = 0; $i < $n; $i++) {
                $bag[] = (int) $sym;
            }
        }

        return $rng->shuffleArray($bag);
    }

    /** @return array<string, array<string, array<string, int>>> win_chances in the legacy band layout */
    private function winChances(int $hit, int $feature): array
    {
        $out = ['spin' => [], 'bonus' => []];
        foreach ([1, 3, 5, 7, 9, 10] as $b) {
            $out['spin']["line{$b}"] = ['74_80' => $hit + 1, '82_88' => $hit, '90_96' => max(1, $hit - 1)];
            $out['bonus']["line{$b}"] = ['74_80' => (int) round($feature * 1.25), '82_88' => (int) round($feature * 1.1), '90_96' => $feature];
        }

        return $out;
    }
}
