<?php

namespace App\Services\RoyalSpin;

use App\Models\GameDesign;

/**
 * Starting points for the Game Builder — complete, playable designs an admin
 * picks from and then edits. Values: line games pay × line bet for n in a row
 * (index = count), cascade games pay × TOTAL bet per tier (settings.tiers).
 */
class DesignPresets
{
    /** @return array<string, string> key => label */
    public static function options(): array
    {
        return array_map(fn (array $p) => $p['label'], self::all());
    }

    /** @return array<string, mixed>|null attributes for a new GameDesign */
    public static function get(string $key): ?array
    {
        return self::all()[$key]['design'] ?? null;
    }

    /** @return array<string, array{label: string, design: array<string, mixed>}> */
    public static function all(): array
    {
        $s = fn (string $name, string $role, string $art, array $pays, int $weight, ?int $weightFree = null) => [
            'name' => $name, 'role' => $role, 'art' => $art, 'image' => null,
            'pays' => $pays, 'weight' => $weight, 'weight_free' => $weightFree,
        ];

        return [
            'olympus-cascade' => [
                'label' => 'Olympus — 6x5 pay anywhere, tumble, multiplier orbs, total multiplier, buy + ante (Gates-style)',
                'design' => [
                    'title' => 'Olympus Thunder',
                    'mechanic' => GameDesign::MECHANIC_CASCADE,
                    'skin' => 'olympus',
                    'art_pack' => 'olympus',
                    'reel_count' => 6,
                    'row_count' => 5,
                    'symbols' => [
                        $s('Blue Gem', 'regular', 'gem_blue', [0.25, 0.75, 2], 14),
                        $s('Green Gem', 'regular', 'gem_green', [0.4, 0.9, 4], 13),
                        $s('Yellow Gem', 'regular', 'gem_yellow', [0.5, 1, 5], 12),
                        $s('Purple Gem', 'regular', 'gem_purple', [0.8, 1.2, 8], 11),
                        $s('Red Gem', 'regular', 'gem_red', [1, 1.5, 10], 10),
                        $s('Chalice', 'regular', 'chalice', [1.5, 2, 12], 8),
                        $s('Ring', 'regular', 'ring', [2, 5, 15], 7),
                        $s('Hourglass', 'regular', 'hourglass', [2.5, 10, 25], 6),
                        $s('Crown', 'regular', 'crown', [10, 25, 50], 5),
                        $s('Thunder God', 'scatter', 'scatter', [], 2, 2),
                        $s('Multiplier Orb', 'multiplier', 'orb', [], 2, 3),
                    ],
                    'settings' => [
                        'cascade_lines' => 20,
                        'tiers' => '8, 10, 12',
                        'scatter_pays' => '4:3, 5:5, 6:100',
                        'trigger' => 4,
                        'free_spins' => 15,
                        'retrigger' => 3,
                        'retrigger_spins' => 5,
                        'multiplier_values' => '2,2,2,2,2,2,3,3,3,3,3,4,4,4,4,5,5,5,5,6,6,6,8,8,10,10,12,12,15,15,20,20,25,50,100,250,500',
                        'multiplier_in_base' => true,
                        'multiplier_accumulate' => true,
                        'buy_feature' => 100,
                        'ante_bet' => 1.25,
                        'volatility' => 'high',
                        'hit_every' => 4,
                        'feature_every' => 220,
                        'bet_options' => '1, 2, 5, 10, 20, 50, 100, 250',
                        'denomination' => 0.01,
                        'show_character' => true,
                    ],
                    'theme' => [
                        'accent' => '#ffd76a',
                        'frame' => ['#fffbe0', '#e0a82e', '#7a4a00'],
                        'reel_bg' => ['#3a0a4acc', '#1a0428e6'],
                        'font' => "Georgia,'Times New Roman',serif",
                        'logo_style' => 'plaque',
                    ],
                ],
            ],

            'olympus-lines' => [
                'label' => 'Olympus — 5x3, 20 lines, crown wild, free spins x3, gamble',
                'design' => [
                    'title' => 'Olympus Riches',
                    'mechanic' => GameDesign::MECHANIC_LINES,
                    'skin' => 'olympus',
                    'art_pack' => 'olympus',
                    'reel_count' => 5,
                    'row_count' => 3,
                    'symbols' => [
                        $s('Blue Gem', 'regular', 'gem_blue', [0, 0, 0, 5, 20, 80], 7),
                        $s('Green Gem', 'regular', 'gem_green', [0, 0, 0, 5, 20, 80], 7),
                        $s('Yellow Gem', 'regular', 'gem_yellow', [0, 0, 0, 5, 25, 100], 7),
                        $s('Purple Gem', 'regular', 'gem_purple', [0, 0, 0, 8, 30, 120], 6),
                        $s('Red Gem', 'regular', 'gem_red', [0, 0, 0, 8, 30, 120], 6),
                        $s('Chalice', 'regular', 'chalice', [0, 0, 0, 10, 50, 250], 4),
                        $s('Ring', 'regular', 'ring', [0, 0, 0, 15, 75, 400], 4),
                        $s('Hourglass', 'regular', 'hourglass', [0, 0, 0, 25, 100, 750], 3),
                        $s('Crown', 'wild', 'crown', [0, 0, 0, 50, 250, 2500], 2),
                        $s('Thunder God', 'scatter', 'scatter', [0, 0, 0, 2, 20, 200], 1),
                    ],
                    'settings' => [
                        'payline_count' => 20,
                        'wild_multiplier' => 1,
                        'free_spins_enabled' => true,
                        'free_spins_count' => 12,
                        'free_spins_table' => '3:12, 4:15, 5:20',
                        'free_spins_multiplier' => 3,
                        'gamble_enabled' => true,
                        'volatility' => 'high',
                        'hit_every' => 4,
                        'feature_every' => 160,
                        'bet_options' => '1, 2, 3, 5, 10, 25, 50, 100',
                        'denomination' => 0.01,
                        'show_character' => true,
                    ],
                    'theme' => [
                        'accent' => '#ffd76a',
                        'frame' => ['#fffbe0', '#e0a82e', '#7a4a00'],
                        'reel_bg' => ['#3a0a4acc', '#1a0428e6'],
                        'font' => "Georgia,'Times New Roman',serif",
                        'logo_style' => 'plaque',
                    ],
                ],
            ],

            'classic-3x3' => [
                'label' => 'Classic fruits — 3x3, 5 lines, star scatter, gamble',
                'design' => [
                    'title' => 'Lucky Fruits',
                    'mechanic' => GameDesign::MECHANIC_LINES,
                    'skin' => 'classic',
                    'art_pack' => 'royal-sevens',
                    'reel_count' => 3,
                    'row_count' => 3,
                    'symbols' => [
                        $s('Cherry', 'regular', '0', [0, 0, 1, 5], 8),
                        $s('Lemon', 'regular', '1', [0, 0, 0, 8], 8),
                        $s('Orange', 'regular', '2', [0, 0, 0, 8], 8),
                        $s('Plum', 'regular', '3', [0, 0, 0, 10], 7),
                        $s('Grapes', 'regular', '4', [0, 0, 0, 20], 5),
                        $s('Watermelon', 'regular', '5', [0, 0, 0, 25], 5),
                        $s('Seven', 'regular', '6', [0, 0, 0, 100], 3),
                        $s('Star', 'scatter', '7', [0, 0, 0, 10], 1),
                    ],
                    'settings' => [
                        'payline_count' => 5,
                        'gamble_enabled' => true,
                        'volatility' => 'low',
                        'hit_every' => 4,
                        'feature_every' => 120,
                        'bet_options' => '2, 4, 10, 20, 40, 100, 200, 400',
                        'denomination' => 0.01,
                    ],
                    'theme' => [
                        'accent' => '#ffd84a',
                        'frame' => ['#fff2a8', '#d4a017', '#7a5200'],
                        'reel_bg' => ['#14141c', '#06060a'],
                        'logo_style' => 'gold',
                    ],
                ],
            ],

            'jewels-5x3' => [
                'label' => 'Jewels — 5x3, 10 lines, crown wild x2, free spins x2',
                'design' => [
                    'title' => 'Jewel Palace',
                    'mechanic' => GameDesign::MECHANIC_LINES,
                    'skin' => 'classic',
                    'art_pack' => 'crown-jewels',
                    'reel_count' => 5,
                    'row_count' => 3,
                    'symbols' => [
                        $s('Sapphire', 'regular', '0', [0, 0, 0, 5, 15, 50], 9),
                        $s('Emerald', 'regular', '1', [0, 0, 0, 5, 15, 50], 9),
                        $s('Amethyst', 'regular', '2', [0, 0, 0, 8, 20, 80], 7),
                        $s('Topaz', 'regular', '3', [0, 0, 0, 8, 20, 80], 7),
                        $s('Ruby', 'regular', '4', [0, 0, 0, 12, 40, 150], 5),
                        $s('Diamond', 'regular', '5', [0, 0, 0, 20, 60, 250], 4),
                        $s('Golden Ring', 'regular', '6', [0, 0, 0, 30, 100, 500], 3),
                        $s('Royal Crown', 'wild', '7', [0, 0, 0, 50, 250, 1000], 2, 3),
                        $s('Treasure Chest', 'scatter', '8', [0, 0, 0, 2, 10, 50], 1),
                    ],
                    'settings' => [
                        'payline_count' => 10,
                        'wild_multiplier' => 2,
                        'free_spins_enabled' => true,
                        'free_spins_count' => 10,
                        'free_spins_table' => '3:10, 4:15, 5:20',
                        'free_spins_multiplier' => 2,
                        'gamble_enabled' => true,
                        'volatility' => 'medium',
                        'hit_every' => 5,
                        'feature_every' => 130,
                        'bet_options' => '1, 2, 5, 10, 20, 50, 100, 200',
                        'denomination' => 0.01,
                    ],
                    'theme' => [
                        'accent' => '#ffd84a',
                        'frame' => ['#fff2a8', '#d4a017', '#7a5200'],
                        'reel_bg' => ['#1a0a40', '#08031a'],
                        'logo_style' => 'gold',
                    ],
                ],
            ],
        ];
    }
}
