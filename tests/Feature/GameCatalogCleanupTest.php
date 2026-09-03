<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameBundle;
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

class GameCatalogCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('game_bundles');
        Storage::fake('public');
    }

    private function shops(): array
    {
        return [
            Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR']),
            Shop::create(['name' => 'B', 'slug' => 'b', 'frontend' => 'default', 'currency' => 'EUR']),
        ];
    }

    private function template(string $code, string $title = ''): GameTemplate
    {
        return GameTemplate::create([
            'code' => $code, 'title' => $title,
            'engine' => 'internal', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
        ]);
    }

    private function publish(GameTemplate $tpl, Shop $shop): Game
    {
        return Game::create([
            'shop_id' => $shop->id, 'template_id' => $tpl->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true,
        ]);
    }

    public function test_dedupe_removes_mobile_twin_when_desktop_covers_every_shop(): void
    {
        [$a, $b] = $this->shops();

        $desktop = $this->template('AgeOfEgyptPT', 'Age Of Egypt');
        $mobile = $this->template('AgeOfEgyptPTM', 'Age Of Egypt');
        $this->publish($desktop, $a);
        $this->publish($desktop, $b);
        $this->publish($mobile, $a);
        $this->publish($mobile, $b);

        $this->artisan('games:dedupe-mobile --force')->assertOk();

        $this->assertModelExists($desktop);
        $this->assertModelMissing($mobile);
        $this->assertDatabaseMissing('games', ['template_id' => $mobile->id]);
    }

    public function test_dedupe_keeps_mobile_twin_that_a_shop_has_no_desktop_for(): void
    {
        [$a, $b] = $this->shops();

        $desktop = $this->template('AztecKing');
        $mobile = $this->template('AztecKingM');
        $this->publish($desktop, $a);       // desktop only in shop A
        $this->publish($mobile, $a);
        $this->publish($mobile, $b);        // …but mobile is also in shop B

        $this->artisan('games:dedupe-mobile --force')->assertOk();

        $this->assertModelExists($mobile);
    }

    public function test_dedupe_ignores_codes_where_m_is_part_of_the_provider_suffix(): void
    {
        [$a] = $this->shops();
        $amatic = $this->template('AdmiralNelsonAM');   // no "AdmiralNelsonA" twin
        $this->publish($amatic, $a);

        $this->artisan('games:dedupe-mobile --force')->assertOk();

        $this->assertModelExists($amatic);
    }

    public function test_dedupe_dry_run_deletes_nothing(): void
    {
        [$a] = $this->shops();
        $desktop = $this->template('WolfGold');
        $mobile = $this->template('WolfGoldM');
        $this->publish($desktop, $a);
        $this->publish($mobile, $a);

        $this->artisan('games:dedupe-mobile')->assertOk();   // no --force

        $this->assertModelExists($mobile);
    }

    public function test_normalize_titles_fills_raw_names_only(): void
    {
        $blank = $this->template('AgeOfEgyptPT', '');
        $raw = $this->template('AncientEgyptClassic', 'AncientEgyptClassic');
        $good = $this->template('BurningHotEGT', 'Burning Hot');

        $this->artisan('games:normalize-titles')->assertOk();

        $this->assertSame('Age Of Egypt', $blank->fresh()->title);
        $this->assertSame('Ancient Egypt Classic', $raw->fresh()->title);
        $this->assertSame('Burning Hot', $good->fresh()->title);   // untouched
    }

    public function test_reresolve_fixes_a_decoy_entry_in_place(): void
    {
        $tpl = $this->template('AfricanKingNG', 'African King');

        // Register with the historical wrong entry, mimicking the old importer.
        $bundle = new GameBundle([
            'version' => 1, 'disk' => 'game_bundles', 'path' => 'africankingng/1',
            'entry' => 'tpl/browserChecker/browserCheckerIOS.html',
            'size' => 0, 'file_count' => 2, 'is_active' => true,
        ]);
        $tpl->bundles()->save($bundle);
        Storage::disk('game_bundles')->put('africankingng/1/tpl/browserChecker/browserCheckerIOS.html', 'stub');
        Storage::disk('game_bundles')->put('africankingng/1/app/africanKing.1/index.html', '<html></html>');

        $this->artisan('bundles:reresolve --dry-run')->assertOk();
        $this->assertSame('tpl/browserChecker/browserCheckerIOS.html', $bundle->fresh()->entry);

        $this->artisan('bundles:reresolve')->assertOk();
        $this->assertSame('app/africanKing.1/index.html', $bundle->fresh()->entry);
    }

    public function test_nested_entry_gets_a_base_href_at_the_entry_directory(): void
    {
        $tpl = $this->template('AdmiralNelsonAM', 'Admiral Nelson');
        $shop = $this->shops()[0];
        GameBank::create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $game = $this->publish($tpl, $shop);
        $player = User::factory()->create(['shop_id' => $shop->id, 'currency' => 'EUR']);
        $player->assignRole('user');
        $token = app(GameLaunch::class)->issueToken($player, $game);

        // A second top-level dir stops commonPrefix() from unwrapping amarent/
        // (real legacy Amatic folders ship admiral/, basic/, slot/ alongside).
        app(BundleManager::class)->store($tpl, $this->zip([
            'amarent/index.html' => '<html><head><title>x</title></head><body>NELSON</body></html>',
            'amarent/js/game.js' => 'x',
            'admiral/theme.json' => '{}',
        ]));

        $this->assertSame('amarent/index.html', $tpl->fresh()->activeBundle->entry);

        $this->get('/games/AdmiralNelsonAM?token='.urlencode($token))
            ->assertOk()
            ->assertSee('NELSON')
            ->assertSee('<base href="'.url('/games/AdmiralNelsonAM/amarent').'/">', false);
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
}
