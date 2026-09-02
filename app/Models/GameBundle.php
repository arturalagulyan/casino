<?php

namespace App\Models;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded front-end bundle version for a game template.
 *
 * @property int $id
 * @property int $game_template_id
 * @property int $version
 * @property string $disk
 * @property string $path
 * @property string $entry
 * @property int $size
 * @property int $file_count
 * @property string|null $checksum
 * @property int|null $uploaded_by
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GameTemplate $template
 * @property-read User|null $uploader
 *
 * @method static Builder<static>|GameBundle newModelQuery()
 * @method static Builder<static>|GameBundle newQuery()
 * @method static Builder<static>|GameBundle query()
 * @method static Builder<static>|GameBundle whereChecksum($value)
 * @method static Builder<static>|GameBundle whereCreatedAt($value)
 * @method static Builder<static>|GameBundle whereDisk($value)
 * @method static Builder<static>|GameBundle whereEntry($value)
 * @method static Builder<static>|GameBundle whereFileCount($value)
 * @method static Builder<static>|GameBundle whereGameTemplateId($value)
 * @method static Builder<static>|GameBundle whereId($value)
 * @method static Builder<static>|GameBundle whereIsActive($value)
 * @method static Builder<static>|GameBundle whereNotes($value)
 * @method static Builder<static>|GameBundle wherePath($value)
 * @method static Builder<static>|GameBundle whereSize($value)
 * @method static Builder<static>|GameBundle whereUpdatedAt($value)
 * @method static Builder<static>|GameBundle whereUploadedBy($value)
 * @method static Builder<static>|GameBundle whereVersion($value)
 *
 * @mixin \Eloquent
 */
class GameBundle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'file_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GameTemplate::class, 'game_template_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** Full path within the bundle dir, or null if it escapes / is missing. */
    public function filePath(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $relative = $relative === '' ? $this->entry : $relative;

        // reject traversal
        if (str_contains($relative, '..')) {
            return null;
        }

        $full = $this->path.'/'.$relative;

        return $this->disk()->exists($full) ? $full : null;
    }
}
