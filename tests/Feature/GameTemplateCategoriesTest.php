<?php

namespace Tests\Feature;

use App\Filament\Resources\GameTemplates\Pages\ListGameTemplates;
use App\Filament\Resources\GameTemplates\Pages\ViewGameTemplate;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameBank;
use App\Models\GameTemplate;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Game templates don't own categories — they inherit them from their per-shop
 * {@see Game} rows. The admin table + view page surface that upward roll-up.
 */
class GameTemplateCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** @return array{0: GameTemplate, 1: Category, 2: Category} */
    private function templateWithCategorisedGames(): array
    {
        $shopA = Shop::create(['name' => 'A', 'slug' => 'a', 'frontend' => 'default', 'currency' => 'EUR']);
        $shopB = Shop::create(['name' => 'B', 'slug' => 'b', 'frontend' => 'default', 'currency' => 'EUR']);
        GameBank::create(['shop_id' => $shopA->id, 'currency' => 'EUR', 'slots' => 1000]);
        GameBank::create(['shop_id' => $shopB->id, 'currency' => 'EUR', 'slots' => 1000]);

        $tpl = GameTemplate::create(['code' => 'RollUpTest', 'title' => 'Roll Up Test', 'engine' => 'internal']);

        $slots = Category::create(['title' => 'Slots', 'slug' => 'slots']);
        $egt = Category::create(['title' => 'EGT', 'slug' => 'egt-cat']);
        $unused = Category::create(['title' => 'Unused', 'slug' => 'unused']);

        $gameA = Game::create(['shop_id' => $shopA->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1]);
        $gameB = Game::create(['shop_id' => $shopB->id, 'template_id' => $tpl->id, 'bank_type' => 'slots', 'denomination' => 1]);
        $gameA->categories()->sync([$slots->id, $egt->id]);
        $gameB->categories()->sync([$slots->id]);   // overlaps → must dedupe

        return [$tpl, $slots, $egt];
    }

    public function test_the_accessor_rolls_up_distinct_sorted_categories(): void
    {
        [$tpl] = $this->templateWithCategorisedGames();

        $this->assertSame(['EGT', 'Slots'], $tpl->fresh()->categories->pluck('title')->all());
    }

    public function test_a_template_without_categorised_games_is_empty(): void
    {
        $tpl = GameTemplate::create(['code' => 'Bare', 'title' => 'Bare', 'engine' => 'internal']);

        $this->assertCount(0, $tpl->categories);
    }

    public function test_the_list_table_shows_and_filters_by_category(): void
    {
        $this->actingAs($this->admin());
        [$tpl, $slots, $egt] = $this->templateWithCategorisedGames();
        $other = GameTemplate::create(['code' => 'Other', 'title' => 'Other', 'engine' => 'internal']);

        Livewire::test(ListGameTemplates::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$tpl, $other])
            ->assertSee('EGT')
            ->set('tableFilters.category.value', (string) $egt->id)
            ->assertCanSeeTableRecords([$tpl])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_view_page_renders_the_inherited_categories(): void
    {
        $this->actingAs($this->admin());
        [$tpl] = $this->templateWithCategorisedGames();

        Livewire::test(ViewGameTemplate::class, ['record' => $tpl->getKey()])
            ->assertOk()
            ->assertSee('Slots')
            ->assertSee('EGT');
    }
}
