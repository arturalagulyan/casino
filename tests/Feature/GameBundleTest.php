<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\GamePlay\BundleManager;
use App\Services\SeamlessWallet\GameLaunch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class GameBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('game_bundles');
    }

    /** @param array<string,string> $files */
    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $archive->addFromString($name, $contents);
        }

        $archive->close();

        return new UploadedFile($path, 'bundle.zip', 'application/zip', null, true);
    }

    private function template(): GameTemplate
    {
        return GameTemplate::create([
            'code' => 'ActionMoney', 'title' => 'Action Money',
            'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
        ]);
    }

    public function test_store_extracts_zip_and_activates_it(): void
    {
        $tpl = $this->template();
        $admin = User::factory()->create();

        $bundle = app(BundleManager::class)->store($tpl, $this->zip([
            'index.html' => '<html><head></head><body>hi</body></html>',
            'js/app.js' => 'console.log(1)',
        ]), $admin);

        $this->assertSame(1, $bundle->version);
        $this->assertTrue($bundle->is_active);
        $this->assertSame('index.html', $bundle->entry);
        $this->assertSame(2, $bundle->file_count);
        $this->assertTrue($tpl->fresh()->hasBundle());
        Storage::disk('game_bundles')->assertExists($bundle->path.'/js/app.js');
    }

    public function test_single_wrapping_folder_is_stripped(): void
    {
        $tpl = $this->template();

        $bundle = app(BundleManager::class)->store($tpl, $this->zip([
            'ActionMoney/index.html' => '<html></html>',
            'ActionMoney/style.css' => 'body{}',
        ]));

        $this->assertSame('index.html', $bundle->entry);
        Storage::disk('game_bundles')->assertExists($bundle->path.'/style.css');
        Storage::disk('game_bundles')->assertMissing($bundle->path.'/ActionMoney/index.html');
    }

    public function test_rejects_bundle_with_php_files(): void
    {
        $tpl = $this->template();

        $this->expectExceptionMessage('executable file');
        app(BundleManager::class)->store($tpl, $this->zip([
            'index.html' => '<html></html>',
            'shell.php' => '<?php system($_GET["c"]);',
        ]));
    }

    public function test_rejects_bundle_without_entry(): void
    {
        $tpl = $this->template();

        $this->expectExceptionMessage('no HTML entry');
        app(BundleManager::class)->store($tpl, $this->zip(['readme.txt' => 'nothing here']));
    }

    public function test_versions_activate_and_delete(): void
    {
        $tpl = $this->template();
        $manager = app(BundleManager::class);

        $v1 = $manager->store($tpl, $this->zip(['index.html' => 'v1']));
        $v2 = $manager->store($tpl, $this->zip(['index.html' => 'v2']));

        $this->assertFalse($v1->fresh()->is_active);
        $this->assertTrue($v2->fresh()->is_active);

        $manager->activate($v1->fresh());
        $this->assertTrue($v1->fresh()->is_active);
        $this->assertFalse($v2->fresh()->is_active);

        $this->expectExceptionMessage('Activate another version');
        $manager->delete($v1->fresh());
    }

    public function test_bundle_is_served_over_http(): void
    {
        [$tpl, $game, $player, $token] = $this->playable();

        app(BundleManager::class)->store($tpl, $this->zip([
            'index.html' => '<html><head><title>Real Game</title></head><body>REAL</body></html>',
            'assets/logo.svg' => '<svg/>',
        ]));

        // entry: token required, bootstrap injected
        $this->get('/games/ActionMoney?token='.urlencode($token))
            ->assertOk()
            ->assertSee('REAL')
            ->assertSee('window.CasinoGame');

        // asset: plain static
        $this->get('/games/ActionMoney/assets/logo.svg')->assertOk()->assertSee('<svg/>', false);

        // traversal blocked
        $this->get('/games/ActionMoney/'.rawurlencode('../secret'))->assertNotFound();
        $this->assertNull(
            $tpl->fresh()->activeBundle->filePath('../../.env')
        );
    }

    /** @return array{0:GameTemplate,1:Game,2:User,3:string} */
    private function playable(): array
    {
        $tpl = $this->template();
        $shop = Shop::create(['name' => 'B', 'slug' => 'b', 'frontend' => 'default', 'currency' => 'EUR']);
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true,
        ]);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');

        $token = app(GameLaunch::class)->issueToken($player, $game);

        return [$tpl, $game, $player, $token];
    }
}
