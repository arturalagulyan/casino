<?php

namespace App\Models;

use App\Services\RoyalSpin\GameBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A RoyalSpin game designed in the admin Game Builder — see {@see GameBuilder}.
 *
 * `symbols` is a list indexed by symbol id:
 *   {name, role: regular|wild|scatter|multiplier, art (art-pack symbol key),
 *    image (uploaded override, public disk), pays: list<float>, weight, weight_free}
 * Line games: pays[n] = × line bet for n in a row (index 0 unused).
 * Cascade games: pays[i] = × TOTAL bet for tier i (settings.tiers).
 *
 * @property int $id
 * @property string $code
 * @property string $title
 * @property string $mechanic
 * @property string $skin
 * @property string|null $art_pack
 * @property int $reel_count
 * @property int $row_count
 * @property array<int, array<string, mixed>>|null $symbols
 * @property array<int, list<int>>|null $paylines
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $theme
 * @property array<string, mixed>|null $assets
 * @property int|null $template_id
 * @property Carbon|null $published_at
 * @property string|null $build_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GameTemplate|null $template
 */
class GameDesign extends Model
{
    public const string MECHANIC_LINES = 'lines';

    public const string MECHANIC_CASCADE = 'cascade';

    protected $fillable = [
        'code', 'title', 'mechanic', 'skin', 'art_pack', 'reel_count', 'row_count',
        'symbols', 'paylines', 'settings', 'theme', 'assets', 'template_id', 'published_at', 'build_hash',
    ];

    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'paylines' => 'array',
            'settings' => 'array',
            'theme' => 'array',
            'assets' => 'array',
            'reel_count' => 'integer',
            'row_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GameTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(GameTemplate::class, 'template_id');
    }

    public function isCascade(): bool
    {
        return $this->mechanic === self::MECHANIC_CASCADE;
    }

    /** One settings value (dot notation), with a default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
