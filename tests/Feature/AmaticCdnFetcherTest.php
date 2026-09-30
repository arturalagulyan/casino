<?php

namespace Tests\Feature;

use App\Services\GamePlay\AmaticCdnFetcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * `amatic:fetch`'s crawler against a miniature fake of Amatic's CDN: it must
 * follow launcher → page → loader → config/engine → manifests → atlases,
 * fonts and sounds, mirror them under the CDN's own paths, and patch the
 * config's socket endpoint.
 */
class AmaticCdnFetcherTest extends TestCase
{
    private string $out;

    private string $engine = 'this.kQv=this.k_G="Demo";this.k3V="Demo Game";this.kdU="_3";x="/core/images/1280_720/load.json"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = storage_path('framework/testing/amatic-fetch');

        $cdn = 'https://cdn.test/gmsl';
        $manifest = json_encode(['resources' => [
            ['id' => 'en', 'url' => '/demo/data/en_1.json', 'type' => 0],
            ['id' => 'de', 'url' => '/demo/data/de_1.json', 'type' => 0],
            ['id' => 'symbols', 'url' => '/demo/images/1280_720/symbols.json', 'type' => 2],
            ['id' => 'bg', 'url' => '/demo/images/1280_720/bg.jpg', 'type' => 2],
            ['id' => 'font', 'url' => '/core/images/1280_720/ui_font.fnt', 'type' => 7],
            ['id' => 'spin', 'url' => '/slot/sounds/spin', 'type' => 4],
            ['id' => 'gone', 'url' => '/slot/sounds/missing', 'type' => 4],
        ]]);

        Http::fake([
            "$cdn/amanet/game.html" => Http::response('<script src="./src/launcher_1.js"></script>'),
            "$cdn/amanet/src/launcher_1.js" => Http::response('request.open("GET", "./data/data_1.json", true);'),
            "$cdn/amanet/data/data_1.json" => Http::response(['games' => ['demo'], 'wildcatgaming' => [], 'classic' => ['demo']]),
            "$cdn/mpp/amarent/demo.html" => Http::response('<link href="game_1.css" rel="stylesheet"/><script src="./src/demoloader_1.js"></script>'),
            "$cdn/mpp/amarent/game_1.css" => Http::response('a{background:url(./images/ico.png)} b{background:url(data:image/png;base64,AAA)}'),
            "$cdn/mpp/amarent/images/ico.png" => Http::response('png'),
            "$cdn/mpp/amarent/src/demoloader_1.js" => Http::response('var scripts = [["game","../demo/src/demo_1.js","UTF-8"]]; scripts.unshift(["config","./src/config_"+getParam("config")+"_9.js","x"]);'),
            "$cdn/mpp/amarent/src/config_1861_9.js" => Http::response("function Config(){\n\tthis.value6 = \"wss://amatic.example/games\";\n}"),
            "$cdn/mpp/demo/src/demo_1.js" => fn () => Http::response($this->engine),
            "$cdn/mpp/demo/data/resources_desktop_3_1280.json" => Http::response($manifest),
            "$cdn/mpp/demo/data/*" => Http::response('{}'),
            "$cdn/mpp/demo/images/1280_720/symbols.json" => Http::response(['meta' => ['image' => 'symbols.png']]),
            "$cdn/mpp/demo/images/1280_720/symbols.png" => Http::response('png'),
            "$cdn/mpp/demo/images/1280_720/symbols.webp" => Http::response('webp'),
            "$cdn/mpp/demo/images/1280_720/bg.jpg" => Http::response('jpg'),
            "$cdn/mpp/core/images/1280_720/ui_font.fnt" => Http::response("<page id='0' file='ui_font'/>"),
            "$cdn/mpp/core/images/1280_720/ui_font.png" => Http::response('png'),
            "$cdn/mpp/core/images/1280_720/load.json" => Http::response(['frames' => []]),
            "$cdn/mpp/slot/sounds/spin.ogg" => Http::response('ogg'),
            '*' => Http::response('', 404),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    public function test_it_mirrors_the_whole_resource_graph_under_cdn_paths(): void
    {
        $game = (new AmaticCdnFetcher)->fetch('https://cdn.test/gmsl/amanet/game.html?game=demo&config=1861&currency=EUR', $this->out);

        $this->assertSame('Demo', $game->gameId);
        $this->assertSame('Demo Game', $game->title);
        $this->assertSame('gmsl/mpp/amarent/demo.html', $game->entry);
        $this->assertSame('EUR', $game->currency);
        $this->assertSame(['de', 'en'], $game->languages);
        $this->assertSame(['classic' => 'true'], $game->urlParams);

        foreach ([
            'mpp/amarent/demo.html', 'mpp/amarent/game_1.css', 'mpp/amarent/images/ico.png',
            'mpp/amarent/src/demoloader_1.js', 'mpp/amarent/src/config_1861_9.js', 'mpp/demo/src/demo_1.js',
            'mpp/demo/data/resources_desktop_3_1280.json', 'mpp/demo/data/en_1.json',
            'mpp/demo/images/1280_720/symbols.png', 'mpp/demo/images/1280_720/symbols.webp',
            'mpp/core/images/1280_720/ui_font.png', 'mpp/core/images/1280_720/load.json',
            'mpp/slot/sounds/spin.ogg',
        ] as $file) {
            $this->assertFileExists("{$this->out}/gmsl/{$file}");
        }

        // Launcher files aren't part of the bundle; the missing sound is reported.
        $this->assertFileDoesNotExist("{$this->out}/gmsl/amanet/game.html");
        $this->assertSame(['gmsl/mpp/slot/sounds/missing.(ogg|m4a|mp3)'], $game->missing);

        $config = (string) file_get_contents("{$this->out}/gmsl/mpp/amarent/src/config_1861_9.js");
        $this->assertStringContainsString("'/socket_config.json'", $config);
        $this->assertStringContainsString('this.value6 = serverString', $config);
        $this->assertStringNotContainsString('amatic.example', $config);
    }

    public function test_it_takes_the_engine_manifest_version_over_stale_ones(): void
    {
        // Admiral-style engine header: three chained ids, no title — and the
        // CDN still serves older manifests (the data/* stub answers every
        // version) that the engine never asks for.
        $this->engine = 'this.a=this.b=this.c="Demo";this.d="_3";x="/core/images/1280_720/load.json"';

        $game = (new AmaticCdnFetcher)->fetch('https://cdn.test/gmsl/amanet/game.html?game=demo&config=1861', $this->out);

        $this->assertSame('Demo', $game->gameId);
        $this->assertFileExists("{$this->out}/gmsl/mpp/demo/data/resources_desktop_3_1280.json");
        $this->assertFileDoesNotExist("{$this->out}/gmsl/mpp/demo/data/resources_desktop_1_1280.json");
        $this->assertFileDoesNotExist("{$this->out}/gmsl/mpp/demo/data/resources_desktop_1280.json");
    }

    public function test_it_needs_the_config_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config=');

        (new AmaticCdnFetcher)->fetch('https://cdn.test/gmsl/amanet/game.html?game=demo', $this->out);
    }

    public function test_it_refuses_other_client_generations(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mpp-generation');

        (new AmaticCdnFetcher)->fetch('https://cdn.test/gmsl/amanet/game.html?game=other&config=1861', $this->out);
    }
}
