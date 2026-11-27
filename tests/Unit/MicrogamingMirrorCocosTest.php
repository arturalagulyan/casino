<?php

namespace Tests\Unit;

use App\Services\GamePlay\Microgaming\MicrogamingMirror;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * Cocos Creator bundles load some packs, and their textures' images, only when
 * a scene opens — a page capture misses them (Book of Mega Moolah's free spins).
 */
class MicrogamingMirrorCocosTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mg-cocos-'.uniqid();
        $files = [
            // packs' members are indices into uuids (or uuids); 3dZvvCnsVD3LTph1YA72LJ = 3d66fbc2-…
            'game/assets/main/config.json' => json_encode([
                'uuids' => ['3dZvvCnsVD3LTph1YA72LJ', '0291c134-b3da-4098-b7b5-e397edbe947f', 'a2MjXRFdtLlYQ5ouAFv/+R'],
                'packs' => ['0aa0a90b9' => [0, 1], '094e11400' => [2]],
            ]),
            'game/assets/main/import/0a/0aa0a90b9.json' => json_encode(['type' => 'cc.Texture2D', 'data' => '1,9729,9729,33071,33071,0,0,1|0,9729,9729,33071,33071,0,0,1']),
            // a mixed pack (Attached): one entry per member, its class index into the classes
            'game/assets/Attached/config.json' => json_encode(['uuids' => ['077fb4e3-4411-4dee-911c-75c154eae533', 'b68OLQq1tGVpZwQq0GPrDt'], 'packs' => ['0fe094ac1' => [0, 1]]]),
            'game/assets/Attached/import/0f/0fe094ac1.json' => json_encode([1, [], [], ['cc.SpriteFrame', 'cc.Texture2D'], [],
                [[['0,9729,9729,33071,33071,0,0,1', -1], [1], 0, [], [], []], [[['name' => 'frame']], [0], 0, [0], [0], [0]]]]),
            // another language's textures are never shown
            'game/assets/Languages/config.json' => json_encode(['uuids' => ['018b962f-8570-4ad2-8fc6-e2b7412dbd7f'], 'packs' => ['0b0000000' => [0]]]),
            'game/assets/Languages/import/0b/0b0000000.json' => json_encode(['type' => 'cc.Texture2D', 'data' => '0,9729,9729,33071,33071,0,0,1']),
        ];
        foreach ($files as $rel => $body) {
            @mkdir(dirname("{$this->dir}/{$rel}"), 0777, true);
            file_put_contents("{$this->dir}/{$rel}", $body);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_compressed_uuid_decodes_to_the_file_name(): void
    {
        $this->assertSame('3d66fbc2-9ec5-43dc-b4e9-875600ef62c9', MicrogamingMirror::cocosUuid('3dZvvCnsVD3LTph1YA72LJ'));
        $this->assertSame('0291c134-b3da-4098-b7b5-e397edbe947f', MicrogamingMirror::cocosUuid('0291c134-b3da-4098-b7b5-e397edbe947f'));
    }

    public function test_every_listed_pack_and_texture_image_is_wanted(): void
    {
        $mirror = (new \ReflectionClass(MicrogamingMirror::class))->newInstanceWithoutConstructor();
        $set = fn (string $prop, mixed $value) => (new \ReflectionProperty(MicrogamingMirror::class, $prop))->setValue($mirror, $value);
        $set('outDir', $this->dir);
        $set('seen', ['game/assets/main/config.json' => true, 'game/assets/Attached/config.json' => true, 'game/assets/Languages/config.json' => true]);
        $call = fn (string $method) => (new \ReflectionMethod(MicrogamingMirror::class, $method))->invoke($mirror, 'game');

        $packs = $call('cocosPacks');
        $this->assertContains('game/assets/main/import/09/094e11400.json', $packs);
        $this->assertContains('game/assets/main/import/0a/0aa0a90b9.json', $packs);

        $this->assertSame([
            'game/assets/main/native/3d/3d66fbc2-9ec5-43dc-b4e9-875600ef62c9.jpg',
            'game/assets/main/native/02/0291c134-b3da-4098-b7b5-e397edbe947f.png',
            'game/assets/Attached/native/07/077fb4e3-4411-4dee-911c-75c154eae533.png',
        ], $call('cocosNatives'));
    }
}
