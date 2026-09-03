<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Casino Control');
    }

    public function test_guest_is_redirected_from_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_open_every_resource_index(): void
    {
        $admin = $this->admin();

        $slugs = [
            'shops', 'users', 'api-keys', 'operators', 'game-templates', 'games', 'categories',
            'jackpots', 'game-banks', 'currency-rates', 'transactions', 'game-rounds', 'roles', 'permissions',
        ];

        foreach ($slugs as $slug) {
            $this->actingAs($admin)
                ->get("/admin/{$slug}")
                ->assertOk("resource {$slug} failed to render");
        }
    }

    public function test_dashboard_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }

    public function test_report_pages_render(): void
    {
        $admin = $this->admin();

        foreach (['cash-report', 'game-stat-report'] as $slug) {
            $this->actingAs($admin)->get("/admin/{$slug}")->assertOk();
        }
    }

    public function test_player_without_panel_permission_is_forbidden(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $player = User::factory()->create();
        $player->assignRole('user');

        $this->actingAs($player)->get('/admin')->assertForbidden();
    }
}
