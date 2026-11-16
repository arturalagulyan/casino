<?php

namespace Tests\Feature;

use App\Enums\ClientProtocol;
use App\Models\GameTemplate;
use App\Services\GamePlay\BundleManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * NetEnt clients fetch the player's currency format off their static host and
 * report a connection error without it. A currency the bundle lacks — NetEnt
 * never had ALL — is made at request time.
 */
class NetEntAssetTest extends TestCase
{
    use RefreshDatabase;

    private function bundle(?ClientProtocol $protocol): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('game_bundles');
        $tpl = GameTemplate::create(['code' => 'TestNetEntGame', 'title' => 'T', 'engine' => 'internal', 'device' => 'both',
            'bank_type' => 'slots', 'default_denomination' => 1, 'client_protocol' => $protocol]);
        $path = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('nth/game/game/index.html', '<html><head></head><body></body></html>');
        $zip->addFromString('nth/currencies/EUR.json', '{"iso":"EUR","own":true}');
        $zip->addFromString('ntt/readme.txt', 'a second top folder: the bundle keeps its layout');
        $zip->close();
        app(BundleManager::class)->store($tpl, new UploadedFile($path, 'b.zip', 'application/zip', null, true), entry: 'nth/game/game/index.html');
    }

    public function test_a_currency_the_bundle_lacks_is_made_in_netents_formats(): void
    {
        $this->bundle(ClientProtocol::NetEnt);

        $this->get('/games/TestNetEntGame/nth/currencies/EUR.json')->assertOk()->assertSee('"own":true', false);   // the bundle's own
        $json = $this->get('/games/TestNetEntGame~v1/nth/currencies/ALL.json')->assertOk()->json();
        $this->assertSame(['ALL', 2, '.', ','], [$json['iso'], $json['decimals'], $json['decimalDivider'], $json['thousandsDivider']]);
        $this->get('/games/TestNetEntGame/ntt/currencies/all/moneyformat.xml')->assertOk()->assertSee('<iso>ALL</iso>', false);
        $this->get('/games/TestNetEntGame/nth/currencies/other.txt')->assertNotFound();
    }

    public function test_other_providers_bundles_get_no_made_up_files(): void
    {
        $this->bundle(null);
        $this->get('/games/TestNetEntGame/nth/currencies/ALL.json')->assertNotFound();
    }
}
