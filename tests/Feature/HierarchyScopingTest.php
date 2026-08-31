<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Shop;
use App\Models\User;
use App\Support\Hierarchy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HierarchyScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_descendant_ids_walk_the_parent_tree(): void
    {
        $agent = User::factory()->create();
        $distributor = User::factory()->create(['parent_id' => $agent->id]);
        $manager = User::factory()->create(['parent_id' => $distributor->id]);
        $stranger = User::factory()->create();

        $ids = Hierarchy::descendantIds($agent);

        $this->assertContains($manager->id, $ids);
        $this->assertContains($distributor->id, $ids);
        $this->assertNotContains($stranger->id, $ids);
    }

    public function test_agent_only_sees_own_shops_and_players(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent');

        $distributor = User::factory()->create(['parent_id' => $agent->id]);
        $myShop = Shop::create(['name' => 'Mine', 'slug' => 'mine', 'frontend' => 'default', 'owner_id' => $distributor->id]);
        $myPlayer = User::factory()->create(['shop_id' => $myShop->id]);
        $myPlayer->assignRole('user');

        $otherShop = Shop::create(['name' => 'Theirs', 'slug' => 'theirs', 'frontend' => 'default']);
        $otherPlayer = User::factory()->create(['shop_id' => $otherShop->id]);
        $otherPlayer->assignRole('user');

        $visibleShops = Shop::visibleTo($agent)->pluck('id');
        $this->assertTrue($visibleShops->contains($myShop->id));
        $this->assertFalse($visibleShops->contains($otherShop->id));

        $visibleUsers = User::visibleTo($agent)->pluck('id');
        $this->assertTrue($visibleUsers->contains($myPlayer->id));
        $this->assertFalse($visibleUsers->contains($otherPlayer->id));
    }

    public function test_admin_sees_everything(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default']);
        Shop::create(['name' => 'B', 'slug' => 'b', 'frontend' => 'default']);

        $this->assertSame(Shop::count(), Shop::visibleTo($admin)->count());

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->assertOk();
    }
}
