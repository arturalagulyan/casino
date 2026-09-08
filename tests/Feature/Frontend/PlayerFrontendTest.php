<?php

namespace Tests\Feature\Frontend;

use App\Enums\UserStatus;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Services\SeamlessWallet\GameLaunch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerFrontendTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config(['frontend.shop_slug' => 'web-casino']);

        $this->shop = Shop::create([
            'name' => 'Web Casino', 'slug' => 'web-casino', 'frontend' => 'default', 'currency' => 'EUR',
        ]);
        GameBank::create(['shop_id' => $this->shop->id, 'currency' => 'EUR', 'slots' => 10000]);
        ApiKey::create(['shop_id' => $this->shop->id, 'name' => 'House Frontend', 'key' => 'house-key', 'is_active' => true]);

        $template = GameTemplate::create([
            'code' => 'SweetBonanzaEGT', 'title' => 'Sweet Bonanza',
            'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1,
        ]);
        $this->game = Game::create([
            'shop_id' => $this->shop->id, 'template_id' => $template->id,
            'bank_type' => 'slots', 'denomination' => 1, 'is_visible' => true,
        ]);
        $category = Category::create(['title' => 'EGT', 'slug' => 'egt', 'position' => 1]);
        $this->game->categories()->attach($category);
    }

    private function player(array $attrs = []): User
    {
        $user = User::create(array_merge([
            'username' => 'player1', 'shop_id' => $this->shop->id, 'currency' => 'EUR',
            'password' => bcrypt('secret12'), 'status' => UserStatus::Active,
        ], $attrs));
        $user->assignRole('user');
        $user->wallet()->update(['balance' => 200, 'currency' => 'EUR']);

        return $user->refresh();
    }

    public function test_player_can_log_in_and_reach_the_lobby(): void
    {
        $this->player();

        $this->post('/login', ['login' => 'player1', 'password' => 'secret12'])
            ->assertRedirect('/');

        $this->get('/')->assertOk()->assertSee('Sweet Bonanza');
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->player();

        $this->from('/login')
            ->post('/login', ['login' => 'player1', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_staff_cannot_use_the_player_frontend(): void
    {
        $staff = $this->player(['username' => 'boss']);
        $staff->assignRole('manager');

        $this->post('/login', ['login' => 'boss', 'password' => 'secret12'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_blocked_player_is_rejected(): void
    {
        $this->player(['is_blocked' => true]);

        $this->post('/login', ['login' => 'player1', 'password' => 'secret12'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_lobby_filters_by_category(): void
    {
        $other = GameTemplate::create(['code' => 'BookOfRaNV', 'title' => 'Book of Ra', 'device' => 'both', 'bank_type' => 'slots', 'default_denomination' => 1]);
        Game::create(['shop_id' => $this->shop->id, 'template_id' => $other->id, 'bank_type' => 'slots', 'denomination' => 1, 'is_visible' => true]);

        $this->actingAs($this->player());

        $this->get('/?category=egt')->assertOk()->assertSee('Sweet Bonanza')->assertDontSee('Book of Ra');
        $this->get('/?q=book')->assertOk()->assertSee('Book of Ra')->assertDontSee('Sweet Bonanza');
    }

    public function test_launch_issues_a_valid_token_and_redirects_to_the_game(): void
    {
        $player = $this->player();

        $res = $this->actingAs($player)->get('/launch/SweetBonanzaEGT');
        $res->assertRedirect();

        $location = $res->headers->get('Location');
        $this->assertStringContainsString('/games/SweetBonanzaEGT?token=', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $resolved = app(GameLaunch::class)->verifyToken($query['token']);

        $this->assertSame($player->id, $resolved['user']->id);
        $this->assertSame($this->game->id, $resolved['game']->id);
    }

    public function test_launch_404s_for_a_game_not_in_the_house_shop(): void
    {
        $this->actingAs($this->player())
            ->get('/launch/NotARealGame')
            ->assertNotFound();
    }

    public function test_balance_endpoint_returns_json(): void
    {
        $this->actingAs($this->player())
            ->getJson('/me/balance')
            ->assertOk()
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('balance', 200);
    }
}
