<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\Shop;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeamlessWalletTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = Shop::create(['name' => 'Sea', 'slug' => 'sea', 'frontend' => 'default', 'currency' => 'EUR']);
        $template = GameTemplate::create([
            'code' => 'PragmaticSweetBonanza', 'title' => 'Sweet Bonanza',
            'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
        ]);
        $game = Game::create([
            'shop_id' => $shop->id, 'template_id' => $template->id, 'bank_type' => 'slots',
            'denomination' => 1, 'is_visible' => true,
        ]);
        $key = ApiKey::create(['shop_id' => $shop->id, 'key' => 'testkey123', 'is_active' => true]);

        return [$shop, $game, $key];
    }

    public function test_launch_creates_player_and_returns_valid_token(): void
    {
        [$shop, $game, $key] = $this->scenario();

        $res = $this->withHeader('X-Api-Key', 'testkey123')->postJson('/api/game/launch', [
            'player_id' => 'ext-42',
            'player_name' => 'Big Winner',
            'balance' => 250.55,
            'currency' => 'EUR',
            'game' => 'PragmaticSweetBonanza',
        ]);

        $res->assertOk()->assertJsonStructure(['launch_url', 'token', 'expires_in']);

        $this->assertDatabaseHas('users', [
            'shop_id' => $shop->id,
            'external_player_id' => 'ext-42',
            'external_provider' => 'api:'.$key->id,
        ]);
        $this->assertDatabaseHas('wallets', ['balance' => 250.5500]);

        $play = $this->getJson('/api/game/play?token='.urlencode($res->json('token')));
        $play->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('game.id', $game->id);
    }

    public function test_launch_rejects_bad_key_and_unknown_game(): void
    {
        $this->scenario();

        $this->withHeader('X-Api-Key', 'nope')->postJson('/api/game/launch', [
            'player_id' => 'x', 'balance' => 1, 'currency' => 'EUR', 'game' => 'PragmaticSweetBonanza',
        ])->assertStatus(401);

        $this->withHeader('X-Api-Key', 'testkey123')->postJson('/api/game/launch', [
            'player_id' => 'x', 'balance' => 1, 'currency' => 'EUR', 'game' => 'NoSuchGame',
        ])->assertStatus(422);
    }

    public function test_play_rejects_tampered_token(): void
    {
        $this->scenario();

        $this->getJson('/api/game/play?token=garbage')->assertStatus(403);
    }
}
