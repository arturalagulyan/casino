<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Game;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes our categories mirror Betboy's provider list: one `provider` category
 * per provider (title, order, icon), and nothing else. Every other category
 * — themes like Christmas or Books, labels like New, providers Betboy doesn't
 * list — becomes a {@see Tag} on the templates of the games it held.
 *
 * Source: a snapshot in `database/data/betboy/` — `providers.json`
 * ([{id, name}] in Betboy's order) and `provider-icons/<id>.svg`. Icons are
 * copied to the public disk as `category-images/provider-<slug>.svg`.
 *
 * Safe to re-run. Categories carrying a game `config` (e.g. Amatic's
 * client_protocol) are never turned into tags — that config drives gameplay.
 */
class ProviderCategorySync
{
    /** Our existing slugs where they differ from Betboy's id (import commands use these). */
    private const array SLUGS = ['play_and_go' => 'playngo'];

    public const string SOURCE = 'database/data/betboy';

    /**
     * @return array{providers: list<string>, tags: list<string>, kept: list<string>, icons: int}
     */
    public function sync(string $sourceDir, bool $dryRun = false): array
    {
        $providers = $this->providers($sourceDir);
        $report = ['providers' => [], 'tags' => [], 'kept' => [], 'icons' => 0];

        DB::beginTransaction();

        try {
            // 1. themes / labels first — some share a slug with a Betboy provider (fishing, crash-games)
            $themes = Category::query()->where(fn ($q) => $q->where('type', '!=', 'provider')->orWhereNull('type'))->get();
            foreach ($themes as $category) {
                $this->convertOrKeep($category, $report);
            }

            // 2. one provider category per Betboy provider
            $synced = [];
            foreach ($providers as $i => $p) {
                $slug = $this->slug($p['id']);
                $category = Category::where('external_ref', "betboy:{$p['id']}")->first()
                    ?? Category::firstOrNew(['slug' => $slug]);

                $category->fill([
                    'slug' => $category->slug ?: $slug,
                    'title' => $p['name'],
                    'type' => 'provider',
                    'external_ref' => "betboy:{$p['id']}",
                    'position' => $i + 1,
                ]);

                $icon = $sourceDir.'/provider-icons/'.$p['id'].'.svg';
                if (File::exists($icon)) {
                    $category->image_path = 'category-images/provider-'.$category->slug.'.svg';
                    if (! $dryRun) {
                        Storage::disk('public')->put($category->image_path, File::get($icon));
                    }
                    $report['icons']++;
                }

                $category->save();
                $synced[] = $category->id;
                $report['providers'][] = $category->slug;
            }

            // 3. provider categories Betboy doesn't list
            foreach (Category::query()->where('type', 'provider')->whereNotIn('id', $synced)->get() as $category) {
                $this->convertOrKeep($category, $report);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    /** @param  array{providers: list<string>, tags: list<string>, kept: list<string>, icons: int}  $report */
    private function convertOrKeep(Category $category, array &$report): void
    {
        if (! empty($category->config) || $category->children()->exists()) {
            $report['kept'][] = (string) $category->slug;

            return;
        }

        $tag = Tag::firstOrCreate(
            ['slug' => $category->slug ?: Str::slug($category->title)],
            ['name' => $category->title, 'image_path' => $category->image_path, 'position' => $category->position],
        );

        $templateIds = Game::query()
            ->whereHas('categories', fn ($q) => $q->whereKey($category->id))
            ->distinct()
            ->pluck('template_id');

        $tag->templates()->syncWithoutDetaching($templateIds);
        $category->delete();

        $report['tags'][] = $tag->slug;
    }

    private function slug(string $betboyId): string
    {
        return self::SLUGS[$betboyId] ?? Str::slug(str_replace('_', '-', $betboyId));
    }

    /** @return list<array{id: string, name: string}> */
    private function providers(string $sourceDir): array
    {
        $file = $sourceDir.'/providers.json';
        $rows = File::exists($file) ? json_decode(File::get($file), true) : null;

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException("No provider list at {$file}.");
        }

        $providers = [];
        foreach ($rows as $row) {
            if (! is_string($row['id'] ?? null) || ! is_string($row['name'] ?? null) || preg_match('/[^A-Za-z0-9_]/', $row['id'])) {
                throw new RuntimeException('Invalid provider row in '.$file.': '.json_encode($row));
            }
            $providers[] = ['id' => $row['id'], 'name' => $row['name']];
        }

        return $providers;
    }
}
