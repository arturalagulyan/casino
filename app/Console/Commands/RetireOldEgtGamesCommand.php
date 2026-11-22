<?php

namespace App\Console\Commands;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameTemplate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Takes the old EGT games — the legacy GamePlatform ones, our own maths —
 * off the player frontend now that the real EGT clients (egt:fetch, client
 * protocol Egt) replace them: their templates go inactive and their games
 * invisible. Nothing is deleted; both stay editable in the admin panel (and
 * can be switched back on there).
 *
 * Old = an EGT template that isn't an egt:fetch import: client protocol
 * GamePlatform, a legacy `…EGT` code, or a game in an "EGT" category.
 *
 *   php artisan egt:retire-old --dry-run
 *   php artisan egt:retire-old
 */
class RetireOldEgtGamesCommand extends Command
{
    protected $signature = 'egt:retire-old {--dry-run : List them, change nothing}';

    protected $description = 'Make the old (legacy GamePlatform) EGT games inactive: hidden on the frontend, kept in the admin panel';

    public function handle(): int
    {
        $templates = GameTemplate::query()
            ->where(fn (Builder $q) => $q->whereNull('client_protocol')->orWhere('client_protocol', '!=', ClientProtocol::Egt->value))
            ->where(fn (Builder $q) => $q
                ->where('client_protocol', ClientProtocol::GamePlatform->value)
                ->orWhere('code', 'like', '%EGT')
                ->orWhereHas('games.categories', fn (Builder $c) => $c->where('slug', 'egt')->orWhere('title', 'EGT')))
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'client_protocol', 'is_active']);

        if ($templates->isEmpty()) {
            $this->info('No old EGT games here.');

            return self::SUCCESS;
        }
        $visible = Game::query()->whereIn('template_id', $templates->pluck('id'))->where('is_visible', true)->count();
        $this->table(['id', 'code', 'title', 'protocol', 'active'], $templates->map(fn (GameTemplate $t) => [
            $t->id, $t->code, $t->title, $t->client_protocol?->value ?? '(category)', $t->is_active ? 'yes' : 'no',
        ])->all());
        $this->line("{$templates->count()} old EGT template(s), {$visible} visible game(s)");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $t = GameTemplate::query()->whereIn('id', $templates->pluck('id'))->update(['is_active' => false]);
        $g = Game::query()->whereIn('template_id', $templates->pluck('id'))->update(['is_visible' => false]);
        $this->info("Made {$t} template(s) inactive and {$g} game(s) invisible.");

        return self::SUCCESS;
    }
}
