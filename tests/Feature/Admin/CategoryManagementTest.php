<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * Categories page backend: CRUD, uniqueness, activation and safe removal.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=CategoryManagementTest`.
 */
class CategoryManagementTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    public function test_super_admin_creates_edits_and_deactivates_a_category(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Footwear'])->assertCreated()->assertJsonPath('data.slug', 'footwear')->json('data.id');

        $this->actingAsAdmin()->patchJson("/api/v1/admin/categories/{$id}", ['name' => 'Shoes'])->assertOk()->assertJsonPath('data.name', 'Shoes');
        $this->actingAsAdmin()->patchJson("/api/v1/admin/categories/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $row = collect($this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/categories?per_page=100')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertFalse($row['is_active']);
        $this->assertArrayHasKey('products_count', $row);
        $this->assertArrayHasKey('sub_categories_count', $row);

        $public = collect($this->guest()->getJson('/api/v1/categories')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($id, $public, 'deactivated categories leave the storefront');
    }

    public function test_names_and_slugs_must_be_unique(): void
    {
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Footwear'])->assertCreated();

        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Footwear'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Other', 'slug' => 'footwear'])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_a_category_with_sub_categories_or_products_cannot_be_deleted(): void
    {
        $cat = Category::factory()->create();
        $sub = SubCategory::factory()->create(['category_id' => $cat->id]);
        $this->makeProduct(['M' => 1], ['sub_category_id' => $sub->id, 'category_id' => $cat->id]);

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/categories/{$cat->id}")->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
        $this->actingAsAdmin()->deleteJson("/api/v1/admin/sub-categories/{$sub->id}")->assertStatus(409);
        $this->assertDatabaseHas('sub_categories', ['id' => $sub->id]);
    }

    public function test_an_empty_category_can_be_removed(): void
    {
        $cat = Category::factory()->create();

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/categories/{$cat->id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }

    public function test_sub_category_names_are_unique_inside_their_category(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['category_id' => $a->id, 'name' => 'Round neck'])->assertCreated();

        $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['category_id' => $a->id, 'name' => 'Round neck'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['category_id' => $b->id, 'name' => 'Round neck', 'slug' => 'round-neck-b'])->assertCreated();
    }
}
