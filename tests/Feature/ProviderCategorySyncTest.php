<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderCategorySyncTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    private Shop $shop;

    private GameTemplate $bookTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        // snapshot: Betboy lists Amatic, Play'n GO, a "Fishing" *provider* and NetEnt
        $this->source = storage_path('framework/testing/betboy-'.uniqid());
        File::ensureDirectoryExists($this->source.'/provider-icons');
        File::put($this->source.'/providers.json', json_encode([
            ['id' => 'amatic', 'name' => 'Amatic'],
            ['id' => 'play_and_go', 'name' => 'Play’n’go'],
            ['id' => 'fishing', 'name' => 'Fishing'],
            ['id' => 'netent', 'name' => 'NetEnt'],
        ]));
        File::put($this->source.'/provider-icons/amatic.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->source.'/provider-icons/netent.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->shop = Shop::create(['name' => 'S', 'slug' => 's', 'frontend' => 'default', 'currency' => 'EUR']);
        $this->bookTemplate = GameTemplate::create(['code' => 'BookGame', 'title' => 'Book Game']);
        $book = Game::create(['shop_id' => $this->shop->id, 'template_id' => $this->bookTemplate->id, 'is_visible' => true]);

        $amatic = Category::create(['slug' => 'amatic', 'title' => 'Amatic', 'type' => 'provider', 'position' => 4, 'config' => ['client_protocol' => 'amatic']]);
        Category::create(['slug' => 'playngo', 'title' => "Play'n GO", 'type' => 'provider', 'position' => 5, 'config' => ['client_protocol' => 'playngo']]);
        $wildcat = Category::create(['slug' => 'wildcat-games', 'title' => 'WildCat Games', 'type' => 'provider', 'position' => 6]);
        $fishingTheme = Category::create(['slug' => 'fishing', 'title' => 'Fishing', 'type' => 'category', 'position' => 20, 'image_path' => 'category-images/category-fishing.svg']);
        Category::create(['slug' => 'christmas', 'title' => 'Christmas', 'type' => 'category', 'position' => 21]);
        Category::create(['slug' => 'routed', 'title' => 'Routed', 'type' => 'category', 'position' => 22, 'config' => ['client_protocol' => 'game_platform']]);

        $book->categories()->attach([$amatic->id, $wildcat->id, $fishingTheme->id]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);
        parent::tearDown();
    }

    private function sync(array $options = []): void
    {
        $this->artisan('categories:sync-providers', ['--source' => $this->source] + $options)->assertSuccessful();
    }

    public function test_categories_become_exactly_the_betboy_providers_in_betboy_order(): void
    {
        $this->sync();

        $providers = Category::where('type', 'provider')->orderBy('position')->get();
        $this->assertSame(['amatic', 'playngo', 'fishing', 'netent'], $providers->pluck('slug')->all());
        $this->assertSame(['Amatic', 'Play’n’go', 'Fishing', 'NetEnt'], $providers->pluck('title')->all());
        $this->assertSame('betboy:play_and_go', $providers[1]->external_ref);

        // existing provider rows are updated in place — config and game links survive
        $amatic = $providers[0];
        $this->assertSame(['client_protocol' => 'amatic'], $amatic->config);
        $this->assertTrue($amatic->games()->where('template_id', $this->bookTemplate->id)->exists());
    }

    public function test_provider_icons_are_published_to_the_public_disk(): void
    {
        $this->sync();

        $amatic = Category::where('slug', 'amatic')->first();
        $this->assertSame('category-images/provider-amatic.svg', $amatic->image_path);
        Storage::disk('public')->assertExists('category-images/provider-amatic.svg');
        Storage::disk('public')->assertExists('category-images/provider-netent.svg');
        $this->assertNull(Category::where('slug', 'fishing')->value('image_path'));
    }

    public function test_every_other_category_becomes_a_tag_on_its_games_templates(): void
    {
        $this->sync();

        $this->assertEqualsCanonicalizing(['fishing', 'christmas', 'wildcat-games'], Tag::pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['fishing', 'wildcat-games'], $this->bookTemplate->tags()->pluck('slug')->all());
        $this->assertSame('category-images/category-fishing.svg', Tag::where('slug', 'fishing')->value('image_path'));
        $this->assertFalse(Category::where('slug', 'wildcat-games')->exists());
        $this->assertFalse(Category::where('slug', 'christmas')->exists());
    }

    public function test_categories_with_game_config_are_never_converted(): void
    {
        $this->assertSame(0, Artisan::call('categories:sync-providers', ['--source' => $this->source]));
        $this->assertStringContainsString('kept as categories (they carry game config or sub-categories): routed', Artisan::output());

        $this->assertSame(['client_protocol' => 'game_platform'], Category::where('slug', 'routed')->firstOrFail()->config);
        $this->assertFalse(Tag::where('slug', 'routed')->exists());
    }

    public function test_command_reports_what_it_did(): void
    {
        $this->assertSame(0, Artisan::call('categories:sync-providers', ['--source' => $this->source]));

        $this->assertStringContainsString('4 provider categories (2 with icons), 3 categories turned into tags, 1 kept.', Artisan::output());
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->sync();
        $snapshot = [Category::orderBy('id')->get(['id', 'slug', 'title', 'position'])->toArray(), Tag::count(), $this->bookTemplate->tags()->count()];

        $this->sync();

        $this->assertSame($snapshot, [Category::orderBy('id')->get(['id', 'slug', 'title', 'position'])->toArray(), Tag::count(), $this->bookTemplate->tags()->count()]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = Category::orderBy('id')->get()->toArray();

        $this->sync(['--dry-run' => true]);

        $this->assertSame($before, Category::orderBy('id')->get()->toArray());
        $this->assertSame(0, Tag::count());
        Storage::disk('public')->assertMissing('category-images/provider-netent.svg');
    }

    public function test_a_backup_is_written_before_syncing(): void
    {
        $this->sync();

        $files = Storage::disk('local')->files('backups');
        $this->assertCount(1, $files);
        $backup = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertContains('christmas', array_column($backup['categories'], 'slug'));
    }

    public function test_repo_snapshot_is_valid(): void
    {
        $providers = json_decode(File::get(base_path('database/data/betboy/providers.json')), true);

        $this->assertCount(46, $providers);
        $this->assertContains('amatic', array_column($providers, 'id'));
        $this->assertContains('play_and_go', array_column($providers, 'id'));
        foreach (File::files(base_path('database/data/betboy/provider-icons')) as $icon) {
            $this->assertDoesNotMatchRegularExpression('/<script|\son[a-z]+=|foreignObject|javascript:/i', $icon->getContents(), $icon->getFilename());
        }
    }
}
