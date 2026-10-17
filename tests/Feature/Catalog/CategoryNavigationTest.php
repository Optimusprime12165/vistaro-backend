<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * Navigation data: GET /me carries the category tree for signed-in customers, GET /categories serves guests.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=CategoryNavigationTest`.
 */
class CategoryNavigationTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    public function test_me_keeps_the_profile_fields_and_adds_active_categories_with_sub_categories(): void
    {
        $footwear = Category::factory()->create(['name' => 'Footwear', 'slug' => 'footwear', 'sort_order' => 1]);
        $shoes = SubCategory::factory()->create(['category_id' => $footwear->id, 'name' => 'Shoes', 'slug' => 'shoes', 'sort_order' => 1]);
        SubCategory::factory()->create(['category_id' => $footwear->id, 'name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
        Category::factory()->create(['name' => 'Retired', 'slug' => 'retired', 'is_active' => false]);

        $user = User::factory()->create();
        $res = $this->actingAsCustomer($user)->getJson('/api/v1/me')->assertOk();

        $res->assertJsonPath('data.id', $user->id)->assertJsonStructure(['data' => ['id', 'name', 'email', 'mobile', 'mobile_verified', 'profile_complete', 'created_at', 'categories']]);

        $cats = collect($res->json('data.categories'));
        $this->assertSame(['footwear'], $cats->pluck('slug')->all(), 'inactive categories never leave the storefront');

        $subs = collect($cats->first()['sub_categories']);
        $this->assertSame(['shoes'], $subs->pluck('slug')->all(), 'inactive sub-categories are hidden');
        $this->assertSame($shoes->id, $subs->first()['id']);
        $this->assertSame($footwear->id, $subs->first()['category_id']);
        $this->assertSame('footwear', $subs->first()['category']['slug']);
    }

    public function test_me_category_tree_matches_the_public_endpoint(): void
    {
        $cat = Category::factory()->create();
        SubCategory::factory()->count(2)->create(['category_id' => $cat->id]);

        $public = $this->guest()->getJson('/api/v1/categories')->assertOk()->json('data');
        $me = $this->actingAsCustomer(User::factory()->create())->getJson('/api/v1/me')->assertOk()->json('data.categories');

        $this->assertSame($public, $me);
    }

    public function test_guests_get_categories_from_the_public_endpoint_and_cannot_call_me(): void
    {
        $cat = Category::factory()->create(['slug' => 'topwear']);
        SubCategory::factory()->create(['category_id' => $cat->id, 'slug' => 't-shirts']);

        $this->guest()->getJson('/api/v1/categories')->assertOk()
            ->assertJsonPath('data.0.slug', 'topwear')
            ->assertJsonPath('data.0.sub_categories.0.slug', 't-shirts')
            ->assertJsonPath('data.0.sub_categories.0.category_id', $cat->id);

        $this->assertContains($this->guest()->getJson('/api/v1/me')->status(), [401, 403]);
    }
}
