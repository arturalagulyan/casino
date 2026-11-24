<?php

namespace App\Console\Commands;

use App\Enums\ClientProtocol;
use App\Models\Game;
use App\Models\GameTemplate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Takes the old Pragmatic games — the legacy re-implementations with our own
 * maths (client protocols Pragmatic / PragmaticTumble / PragmaticPayline, the
 * `…PM` / `…PMM` codes) — off the player frontend now that the real Pragmatic
 * clients (pragmatic:fetch, client protocol PragmaticPlay) replace them:
 * their templates go inactive and their games invisible. Nothing is deleted;
 * both stay editable in the admin panel (and can be switched back on there).
 *
 * Old = a template that isn't a pragmatic:fetch import and has a legacy
 * Pragmatic protocol, a `…PM`/`…PMM` code, or a game in the "Pragmatic" category.
 *
 *   php artisan pragmatic:retire-old --dry-run
 *   php artisan pragmatic:retire-old
 */
class RetireOldPragmaticGamesCommand extends Command
{
    protected $signature = 'pragmatic:retire-old {--dry-run : List them, change nothing}';

    protected $description = 'Make the old (legacy) Pragmatic games inactive: hidden on the frontend, kept in the admin panel';

    public function handle(): int
    {
        $legacy = [ClientProtocol::Pragmatic->value, ClientProtocol::PragmaticTumble->value, ClientProtocol::PragmaticPayline->value];
        $templates = GameTemplate::query()
            ->where(fn (Builder $q) => $q->whereNull('client_protocol')->orWhere('client_protocol', '!=', ClientProtocol::PragmaticPlay->value))
            ->where(fn (Builder $q) => $q
                ->whereIn('client_protocol', $legacy)
                ->orWhereRaw("BINARY code LIKE '%PM'")    // case-sensitive: the legacy suffix, not any "…pm"
                ->orWhereRaw("BINARY code LIKE '%PMM'")
                ->orWhereHas('games.categories', fn (Builder $c) => $c->where('slug', 'pragmatic')->orWhere('title', 'Pragmatic')))
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'client_protocol', 'is_active']);

        if ($templates->isEmpty()) {
            $this->info('No old Pragmatic games here.');

            return self::SUCCESS;
        }
        $visible = Game::query()->whereIn('template_id', $templates->pluck('id'))->where('is_visible', true)->count();
        $this->table(['id', 'code', 'title', 'protocol', 'active'], $templates->map(fn (GameTemplate $t) => [
            $t->id, $t->code, $t->title, $t->client_protocol?->value ?? '(category)', $t->is_active ? 'yes' : 'no',
        ])->all());
        $this->line("{$templates->count()} old Pragmatic template(s), {$visible} visible game(s)");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $t = GameTemplate::query()->whereIn('id', $templates->pluck('id'))->update(['is_active' => false]);
        $g = Game::query()->whereIn('template_id', $templates->pluck('id'))->update(['is_visible' => false]);
        $this->info("Made {$t} template(s) inactive and {$g} game(s) invisible.");

        return self::SUCCESS;
    }
}
