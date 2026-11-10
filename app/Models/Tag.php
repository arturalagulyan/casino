<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A game tag — a theme or label (Christmas, Books, Fruits, New…). Providers
 * are {@see Category} rows; everything else that groups games is a tag.
 * Tags attach to the master {@see GameTemplate}, so they apply in every shop.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $image_path
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, GameTemplate> $templates
 */
class Tag extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsToMany<GameTemplate, $this> */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(GameTemplate::class)->withTimestamps();
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
