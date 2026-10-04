// RoyalSpin — game math generator.
//
// Writes resources/games/royalspin/games/<Code>/math.json: everything the
// server engine needs (grid, symbols, paytable, paylines, reel strips, feature
// rules). `php artisan royalspin:install` turns each math.json into a
// game_templates row. Strips are built from per-reel symbol weights with a
// seeded shuffle, so re-running this produces byte-identical output.
//
//   node resources/games/royalspin/tools/make-math.mjs

import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'games');

// mulberry32 — tiny seeded PRNG
function rng(seed) {
    return () => {
        seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** weights: {symbol: count} → shuffled strip with no identical neighbours where avoidable. */
function strip(weights, seed) {
    const rand = rng(seed);
    const bag = [];
    for (const [sym, n] of Object.entries(weights)) for (let i = 0; i < n; i++) bag.push(+sym);
    for (let i = bag.length - 1; i > 0; i--) {
        const j = Math.floor(rand() * (i + 1));
        [bag[i], bag[j]] = [bag[j], bag[i]];
    }
    // break up runs of 3+ of the same symbol (keeps stacks short and boards varied)
    for (let pass = 0; pass < 4; pass++) {
        for (let i = 2; i < bag.length; i++) {
            if (bag[i] === bag[i - 1] && bag[i] === bag[i - 2]) {
                const j = (i + 3 + Math.floor(rand() * (bag.length - 6))) % bag.length;
                [bag[i], bag[j]] = [bag[j], bag[i]];
            }
        }
    }
    return bag;
}

function strips(perReel, seed, prefix = 'reelStrip') {
    const out = {};
    perReel.forEach((w, i) => { out[prefix + (i + 1)] = strip(w, seed + i * 7919); });
    return out;
}

const LINES_20 = [
    [1, 1, 1, 1, 1], [0, 0, 0, 0, 0], [2, 2, 2, 2, 2], [0, 1, 2, 1, 0], [2, 1, 0, 1, 2],
    [0, 0, 1, 2, 2], [2, 2, 1, 0, 0], [1, 0, 0, 0, 1], [1, 2, 2, 2, 1], [1, 0, 1, 2, 1],
    [1, 2, 1, 0, 1], [0, 1, 1, 1, 0], [2, 1, 1, 1, 2], [0, 1, 0, 1, 0], [2, 1, 2, 1, 2],
    [1, 1, 0, 1, 1], [1, 1, 2, 1, 1], [0, 0, 2, 0, 0], [2, 2, 0, 2, 2], [0, 2, 2, 2, 0],
];

function winChances(spin, bonus) {
    // spin / bonus = 1-in-N per RTP band [74_80, 82_88, 90_96]; same for every line bucket
    const out = { spin: {}, bonus: {} };
    for (const b of [1, 3, 5, 7, 9, 10]) {
        out.spin[`line${b}`] = { '74_80': spin[0], '82_88': spin[1], '90_96': spin[2] };
        out.bonus[`line${b}`] = { '74_80': bonus[0], '82_88': bonus[1], '90_96': bonus[2] };
    }
    return out;
}

const games = {
    // ---- Classic 5x3 fruit slot, 5 fixed lines, star scatter pays anywhere --
    RoyalSevensRS: {
        title: 'Royal Sevens',
        reel_count: 5, row_count: 3,
        symbols: [0, 1, 2, 3, 4, 5, 6, 7],
        wild_symbol: null, scatter_symbol: 7,
        min_match: 2,
        // index = symbols in a row (×bet per line); scatter row = ×total bet
        paytable: {
            0: [0, 0, 1, 4, 10, 40],       // cherry
            1: [0, 0, 0, 4, 10, 40],       // lemon
            2: [0, 0, 0, 4, 10, 40],       // orange
            3: [0, 0, 0, 4, 10, 40],       // plum
            4: [0, 0, 0, 10, 40, 100],     // grapes
            5: [0, 0, 0, 10, 40, 100],     // watermelon
            6: [0, 0, 0, 20, 200, 1000],   // seven
            7: [0, 0, 0, 2, 10, 50],       // star (scatter)
        },
        paylines: LINES_20.slice(0, 5),
        reel_strips: strips(Array(5).fill({ 0: 8, 1: 8, 2: 8, 3: 8, 4: 5, 5: 5, 6: 3, 7: 1 }), 1101),
        has_bonus: true, has_free_spins: false, free_spins_count: 0,
        has_gamble: true, gamble_win_chance: 2,
        volatility: 'low',
        win_chances: winChances([5, 4, 3], [140, 110, 80]),
        default_bet_options: [2, 4, 10, 20, 40, 100, 200, 400],
        default_denomination: 0.01,
    },

    // ---- 5x3 jewels, 10 lines, crown wild ×2, chest scatter → free spins ×2 --
    CrownJewelsRS: {
        title: 'Crown Jewels',
        reel_count: 5, row_count: 3,
        symbols: [0, 1, 2, 3, 4, 5, 6, 7, 8],
        wild_symbol: 7, scatter_symbol: 8, wild_multiplier: 2,
        min_match: 3,
        paytable: {
            0: [0, 0, 0, 5, 15, 50],       // sapphire
            1: [0, 0, 0, 5, 15, 50],       // emerald
            2: [0, 0, 0, 8, 20, 80],       // amethyst
            3: [0, 0, 0, 8, 20, 80],       // topaz
            4: [0, 0, 0, 12, 40, 150],     // ruby
            5: [0, 0, 0, 20, 60, 250],     // diamond
            6: [0, 0, 0, 30, 100, 500],    // golden ring
            7: [0, 0, 0, 50, 250, 1000],   // crown (wild)
            8: [0, 0, 0, 2, 10, 50],       // chest (scatter, × total bet)
        },
        paylines: LINES_20.slice(0, 10),
        reel_strips: {
            ...strips(Array(5).fill({ 0: 9, 1: 9, 2: 7, 3: 7, 4: 5, 5: 4, 6: 3, 7: 2, 8: 1 }), 2201),
            ...strips(Array(5).fill({ 0: 8, 1: 8, 2: 7, 3: 7, 4: 5, 5: 5, 6: 4, 7: 3, 8: 1 }), 2301, 'reelStripBonus'),
        },
        has_bonus: true, has_free_spins: true, free_spins_count: 10,
        free_spins_table: [0, 0, 0, 10, 15, 20], free_spins_multiplier: 2,
        has_gamble: true, gamble_win_chance: 2,
        volatility: 'medium',
        win_chances: winChances([6, 5, 4], [160, 130, 100]),
        default_bet_options: [1, 2, 5, 10, 20, 50, 100, 200],
        default_denomination: 0.01,
    },

    // ---- 5x3 Egyptian, 20 lines, sun-disc wild, pyramid scatter → 12 FS ×3 --
    PharaohsRichesRS: {
        title: "Pharaoh's Riches",
        reel_count: 5, row_count: 3,
        symbols: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        wild_symbol: 9, scatter_symbol: 10, wild_multiplier: 1,
        min_match: 3,
        paytable: {
            0: [0, 0, 0, 5, 25, 100],      // 10
            1: [0, 0, 0, 5, 25, 100],      // J
            2: [0, 0, 0, 5, 25, 100],      // Q
            3: [0, 0, 0, 5, 40, 150],      // K
            4: [0, 0, 0, 5, 40, 150],      // A
            5: [0, 0, 0, 10, 50, 250],     // ankh
            6: [0, 0, 0, 10, 50, 250],     // scarab
            7: [0, 0, 0, 20, 100, 500],    // eye of horus
            8: [0, 0, 0, 30, 200, 1000],   // pharaoh
            9: [0, 0, 0, 50, 250, 2500],   // sun disc (wild)
            10: [0, 0, 0, 2, 20, 200],     // pyramid (scatter, × total bet)
        },
        paylines: LINES_20,
        reel_strips: {
            ...strips(Array(5).fill({ 0: 7, 1: 7, 2: 7, 3: 6, 4: 6, 5: 4, 6: 4, 7: 3, 8: 2, 9: 2, 10: 1 }), 3301),
            ...strips(Array(5).fill({ 0: 6, 1: 6, 2: 6, 3: 5, 4: 5, 5: 4, 6: 4, 7: 3, 8: 3, 9: 3, 10: 1 }), 3401, 'reelStripBonus'),
        },
        has_bonus: true, has_free_spins: true, free_spins_count: 12,
        free_spins_table: [0, 0, 0, 12, 12, 12], free_spins_multiplier: 3,
        has_gamble: true, gamble_win_chance: 2,
        volatility: 'high',
        win_chances: winChances([5, 4, 3], [200, 160, 120]),
        default_bet_options: [1, 2, 3, 5, 10, 25, 50, 100],
        default_denomination: 0.01,
    },

    // ---- 6x5 scatter-pays cascade, 8+ anywhere pays, bombs in free spins ----
    CandyRoyaleRS: {
        title: 'Candy Royale',
        reel_count: 6, row_count: 5,
        symbols: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        wild_symbol: null, scatter_symbol: 8,
        min_match: 3,
        // tier-indexed (8-9 / 10-11 / 12+ anywhere), × bet unit (stake = 20 units)
        paytable: {
            0: [5, 15, 40],        // jelly bean
            1: [8, 18, 50],        // blue drop
            2: [10, 20, 60],       // green cube
            3: [12, 25, 80],       // purple swirl
            4: [16, 40, 120],      // orange star
            5: [30, 60, 200],      // lollipop
            6: [40, 100, 300],     // cupcake
            7: [100, 250, 1000],   // royal heart
            8: [0, 0, 0],          // scatter — pays via tumble_config.scatter_pays
            9: [0, 0, 0],          // multiplier bomb
        },
        paylines: [],
        reel_strips: {
            ...strips(Array(6).fill({ 0: 12, 1: 11, 2: 10, 3: 9, 4: 7, 5: 5, 6: 4, 7: 3, 8: 1 }), 4401),
            ...strips(Array(6).fill({ 0: 11, 1: 10, 2: 10, 3: 9, 4: 7, 5: 5, 6: 4, 7: 3, 8: 1, 9: 2 }), 4501, 'reelStripBonus'),
        },
        tumble_config: {
            lines: 20,
            tiers: [8, 10, 12],
            scatter_pays: { 4: 60, 5: 100, 6: 2000 },
            trigger: 4, retrigger: 3, retrigger_spins: 5, free_spins: 10,
            multiplier_symbol: 9,
            multiplier_values: [2, 2, 2, 2, 3, 3, 3, 4, 4, 5, 5, 6, 8, 10, 12, 15, 20, 25, 50, 100],
        },
        has_bonus: true, has_free_spins: true, free_spins_count: 10,
        has_gamble: false,
        volatility: 'high',
        win_chances: winChances([5, 4, 3], [260, 220, 180]),
        default_bet_options: [1, 2, 5, 10, 20, 50, 100, 250],
        default_denomination: 0.01,
    },
};

for (const [code, math] of Object.entries(games)) {
    math.symbol_count = math.symbols.length;
    const file = join(root, code, 'math.json');
    mkdirSync(dirname(file), { recursive: true });
    // pretty-print, but keep number arrays (strips, paylines, paytable rows) on one line
    const json = JSON.stringify(math, null, 2)
        .replace(/\[\s*(-?[\d.]+(?:\s*,\s*-?[\d.]+)*)\s*\]/g, (_, nums) => '[' + nums.split(/\s*,\s*/).join(',') + ']');
    writeFileSync(file, json + '\n');
    console.log('wrote', file);
}
