<?php

namespace App\Console\Commands;

use App\Enums\ClientProtocol;
use App\Models\GameTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * EGT "GamePlatform" bundles are portal shells: the client boots into the game
 * whose `gameType` the server names in its login `complex` map, then loads
 * `html5/games/<GameSlot>/Game.min.js`. The real gameType is the one `<XX>JSlot`
 * subfolder sitting inside that game folder (e.g. `ActionMoneySlot/AMJSlot/`,
 * `BaseSlot/ZWJSlot/`). Nothing in the DB or the legacy dump carries it, so we
 * read it off the bundle and cache it on `game_templates.layout['egt']`.
 *
 * (The `library-single-game-v<n>.json` the client asks for once its game list
 * has one entry is aliased at request time by GameAssetController@asset — the
 * bundle files are never touched. `--clean` removes copies an earlier run made.)
 */
class ExtractEgtLayoutCommand extends Command
{
    protected $signature = 'egt:extract-layout {--dry-run} {--clean : delete library-single-game copies a previous run wrote}';

    protected $description = 'Read each EGT bundle\'s real gameType into game_templates.layout';

    private const string ENTRY_SUFFIX = '/(JSlot|JPoker|Keno|Roulette|Bingo|Slot|Poker)$/';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = Storage::disk('game_bundles');

        $set = $libs = $missing = 0;
        $rows = [];

        GameTemplate::query()
            ->where('client_protocol', ClientProtocol::GamePlatform)
            ->with('activeBundle')
            ->orderBy('code')
            ->each(function (GameTemplate $t) use ($disk, $dry, &$set, &$libs, &$missing, &$rows) {
                $bundle = $t->activeBundle;
                if (! $bundle) {
                    $missing++;

                    return;
                }
                $root = $disk->path($bundle->path).'/html5';
                $gameType = $this->gameTypeFrom($root.'/games');

                if ($gameType) {
                    $layout = $t->layout ?? [];
                    $layout['egt'] = array_merge($layout['egt'] ?? [], [
                        'game_type' => $gameType,
                        'gin' => 100000 + $t->id,
                    ]);
                    $rows[] = [$t->code, $gameType, 100000 + $t->id];
                    if (! $dry) {
                        $t->update(['layout' => $layout]);
                    }
                    $set++;
                } else {
                    $missing++;
                    $rows[] = [$t->code, '<not found>', ''];
                }

                if ($this->option('clean') && ! $dry && $this->cleanSingleGameLibrary($root.'/assets')) {
                    $libs++;
                }
            });

        if ($rows !== []) {
            $this->table(['Code', 'gameType', 'gin'], $rows);
        }
        $this->newLine();
        $this->info(sprintf('%s gameType set=%d  library copies removed=%d  unresolved=%d', $dry ? 'Dry run —' : 'Done.', $set, $libs, $missing));

        return self::SUCCESS;
    }

    /** The single `<XX>JSlot` folder inside the bundle's one real game folder. */
    private function gameTypeFrom(string $gamesDir): ?string
    {
        if (! is_dir($gamesDir)) {
            return null;
        }

        foreach (File::directories($gamesDir) as $outer) {
            if (preg_match('/(^|\/)common/i', $outer) === 1) {
                continue;
            }
            foreach (File::directories($outer) as $inner) {
                $name = basename($inner);
                if ($name !== 'assets' && preg_match(self::ENTRY_SUFFIX, $name) === 1) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** Remove `library-single-game-v<n>.{json,png}` copies an earlier run made. */
    private function cleanSingleGameLibrary(string $assetsDir): bool
    {
        if (! is_dir($assetsDir)) {
            return false;
        }
        $did = false;
        foreach (File::glob($assetsDir.'/library-single-game-v*.{json,png}', GLOB_BRACE) as $file) {
            @unlink($file);
            $did = true;
        }

        return $did;
    }
}
