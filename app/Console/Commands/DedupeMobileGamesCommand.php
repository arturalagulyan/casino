<?php

namespace App\Console\Commands;

use App\Models\GameTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Legacy shipped a separate `<Code>M` template for the mobile build of a game
 * even though the engine config is identical to the desktop `<Code>`. The
 * rebuild's front-ends are responsive, so the mobile twin is pure duplication.
 *
 * This removes `<Code>M` when a desktop `<Code>` exists AND is published to every
 * shop the mobile one is — so no shop loses a game. Everything else is reported,
 * not touched.
 */
class DedupeMobileGamesCommand extends Command
{
    protected $signature = 'games:dedupe-mobile
        {--dry-run : Report the plan, delete nothing}
        {--force : Actually delete (without this, behaves like --dry-run)}';

    protected $description = 'Delete redundant "<Code>M" mobile-duplicate game templates';

    public function handle(): int
    {
        $apply = (bool) $this->option('force') && ! (bool) $this->option('dry-run');

        /** @var array<string, GameTemplate> $byCode */
        $byCode = GameTemplate::query()->get()->keyBy('code')->all();

        $deleted = $keptMobileOnly = $notTwin = 0;
        $delRows = [];
        $keepRows = [];

        foreach ($byCode as $code => $template) {
            if (! str_ends_with($code, 'M')) {
                continue;
            }
            $desktopCode = substr($code, 0, -1);
            $desktop = $byCode[$desktopCode] ?? null;

            if (! $desktop) {
                $notTwin++;

                continue; // "…AM"/"…GM"/standalone game ending in M — not a mobile build
            }

            $mobileShops = $template->games()->pluck('shop_id')->unique();
            $desktopShops = $desktop->games()->pluck('shop_id')->unique();
            $orphanShops = $mobileShops->diff($desktopShops);

            if ($orphanShops->isNotEmpty()) {
                $keptMobileOnly++;
                $keepRows[] = [$code, $desktopCode, 'shops '.$orphanShops->implode(',').' have no desktop copy'];

                continue;
            }

            $deleted++;
            $delRows[] = [$code, $desktopCode, $template->games()->count().' game rows'];

            if ($apply) {
                $slug = Str::slug($code) ?: 'game-'.$template->id;
                $poster = $template->poster_path;

                DB::transaction(fn () => $template->delete()); // cascades games → category_game, game_bundles

                Storage::disk('game_bundles')->deleteDirectory($slug);
                if ($poster && $poster !== $desktop->poster_path) {
                    Storage::disk('public')->delete($poster);
                }
            }
        }

        if ($delRows !== []) {
            $this->output->section($apply ? 'Deleted' : 'Would delete');
            $this->table(['Mobile code', 'Desktop twin', 'Cascades'], $delRows);
        }
        if ($keepRows !== []) {
            $this->output->section('Kept — mobile-only in some shop');
            $this->table(['Mobile code', 'Desktop twin', 'Reason'], $keepRows);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s  deleted=%d  kept(mobile-only)=%d  not-a-twin=%d',
            $apply ? 'Done.' : 'Dry run —',
            $deleted,
            $keptMobileOnly,
            $notTwin,
        ));
        if (! $apply && $deleted > 0) {
            $this->comment('Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }
}
