<?php

namespace Tests\Feature\Catalog;

use App\Enums\AdminRole;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\OfflineStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    // ------------------------------------------------------- authorization

    public function test_every_admin_catalog_route_rejects_anonymous_and_customer_tokens(): void
    {
        $p = $this->makeProduct();
        $c = Category::factory()->create();
        $s = SubCategory::factory()->create();

        $routes = [
            ['get', '/api/v1/admin/categories'], ['post', '/api/v1/admin/categories'], ['get', "/api/v1/admin/categories/{$c->id}"],
            ['patch', "/api/v1/admin/categories/{$c->id}"], ['delete', "/api/v1/admin/categories/{$c->id}"],
            ['get', '/api/v1/admin/sub-categories'], ['post', '/api/v1/admin/sub-categories'], ['delete', "/api/v1/admin/sub-categories/{$s->id}"],
            ['get', '/api/v1/admin/products'], ['post', '/api/v1/admin/products'], ['get', "/api/v1/admin/products/{$p->id}"],
            ['patch', "/api/v1/admin/products/{$p->id}"], ['delete', "/api/v1/admin/products/{$p->id}"],
            ['post', "/api/v1/admin/products/{$p->id}/images"], ['put', "/api/v1/admin/products/{$p->id}/colors"],
            ['put', "/api/v1/admin/products/{$p->id}/sizes"], ['put', "/api/v1/admin/products/{$p->id}/stock"],
            ['post', "/api/v1/admin/products/{$p->id}/stock/adjust"], ['get', '/api/v1/admin/inventory'], ['get', '/api/v1/admin/inventory/low-stock'],
        ];

        foreach ($routes as [$method, $url]) {
            $this->guest()->json($method, $url, [])->assertUnauthorized();
            $this->actingAsCustomer()->json($method, $url, [])->assertUnauthorized();
        }
        $this->assertSame(1, Product::count(), 'nothing was changed by the rejected calls');
    }

    public function test_staff_can_read_and_manage_stock_but_not_change_the_catalogue(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $c = Category::factory()->create();
        $payload = $this->productPayload();             // creates fixture rows, so do it before counting
        $categoriesBefore = Category::count();

        foreach (['/api/v1/admin/categories', '/api/v1/admin/products', "/api/v1/admin/products/{$p->id}", '/api/v1/admin/inventory', '/api/v1/admin/inventory/low-stock'] as $url) {
            $this->actingAsAdmin(AdminRole::Staff)->getJson($url)->assertOk();
        }
        $this->actingAsAdmin(AdminRole::Staff)->putJson("/api/v1/admin/products/{$p->id}/stock", ['location' => 'online', 'sizes' => ['M' => 11]])->assertOk();

        $writes = [
            ['post', '/api/v1/admin/categories', ['name' => 'X']], ['patch', "/api/v1/admin/categories/{$c->id}", ['name' => 'Y']],
            ['delete', "/api/v1/admin/categories/{$c->id}", []], ['post', '/api/v1/admin/products', $payload],
            ['patch', "/api/v1/admin/products/{$p->id}", ['name' => 'Hacked']], ['delete', "/api/v1/admin/products/{$p->id}", []],
            ['put', "/api/v1/admin/products/{$p->id}/colors", ['colors' => []]], ['put', "/api/v1/admin/products/{$p->id}/sizes", ['sizes' => [['size' => 'S']]]],
            ['post', "/api/v1/admin/products/{$p->id}/images", ['urls' => ['https://example.com/a.jpg']]],
        ];
        foreach ($writes as [$method, $url, $body]) {
            $this->actingAsAdmin(AdminRole::Staff)->json($method, $url, $body)->assertForbidden()->assertJsonPath('success', false);
        }
        $this->assertNotSame('Hacked', $p->fresh()->name);
        $this->assertSame($categoriesBefore, Category::count(), 'staff could not create or delete categories');
        $this->assertSame(11, $p->sizes()->first()->quantity, 'but the staff stock change was applied');
    }

    public function test_admin_and_super_admin_can_manage_the_catalogue(): void
    {
        foreach ([AdminRole::Admin, AdminRole::SuperAdmin] as $i => $role) {
            $this->actingAsAdmin($role)->postJson('/api/v1/admin/categories', ['name' => "Cat {$i}"])->assertCreated();
        }
        $this->assertSame(2, Category::count());
    }

    // ------------------------------------------------------------ categories

    public function test_category_crud_with_generated_unique_slugs(): void
    {
        $r = $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Spring Wear', 'description' => 'Light layers', 'sort_order' => 4])
            ->assertCreated()->assertJsonPath('data.slug', 'spring-wear')->assertJsonPath('data.is_active', true)->assertJsonPath('data.sort_order', 4);
        $id = $r->json('data.id');

        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Spring Wear'])->assertCreated()->assertJsonPath('data.slug', 'spring-wear-2');
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'X', 'slug' => 'spring-wear'])->assertStatus(422)->assertJsonStructure(['errors' => ['slug']]);
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Bad', 'slug' => 'Bad Slug!'])->assertStatus(422);
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', [])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);

        $this->actingAsAdmin()->getJson("/api/v1/admin/categories/{$id}")->assertOk()->assertJsonPath('data.name', 'Spring Wear')->assertJsonPath('data.sub_categories_count', 0);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/categories/{$id}", ['name' => 'Spring', 'is_active' => false, 'slug' => ''])
            ->assertOk()->assertJsonPath('data.name', 'Spring')->assertJsonPath('data.slug', 'spring-wear')->assertJsonPath('data.is_active', false);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/categories/{$id}", ['slug' => 'spring-wear'])->assertOk();    // own slug is fine

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/categories/{$id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $id]);
        $this->actingAsAdmin()->getJson("/api/v1/admin/categories/{$id}")->assertNotFound();
    }

    public function test_category_list_is_paginated_searchable_filterable_and_sortable(): void
    {
        foreach (range(1, 25) as $i) {
            Category::factory()->create(['name' => sprintf('Cat %02d', $i), 'sort_order' => 100 - $i, 'is_active' => $i % 5 !== 0]);
        }

        $this->actingAsAdmin()->getJson('/api/v1/admin/categories')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 3);
        $this->actingAsAdmin()->getJson('/api/v1/admin/categories?per_page=20')->assertJsonCount(20, 'data');
        $this->actingAsAdmin()->getJson('/api/v1/admin/categories?per_page=101')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/categories?q=Cat 07')->assertJsonPath('meta.total', 1);
        $this->actingAsAdmin()->getJson('/api/v1/admin/categories?is_active=0')->assertJsonPath('meta.total', 5);
        $first = $this->actingAsAdmin()->getJson('/api/v1/admin/categories?sort=name&direction=asc')->json('data.0.name');
        $this->assertSame('Cat 01', $first);
        $this->actingAsAdmin()->getJson('/api/v1/admin/categories?sort=password')->assertStatus(422);      // sort whitelist
    }

    public function test_category_with_sub_categories_cannot_be_deleted(): void
    {
        $sub = SubCategory::factory()->create();

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/categories/{$sub->category_id}")->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('categories', ['id' => $sub->category_id]);
    }

    public function test_category_images_are_validated_stored_and_replaced(): void
    {
        config(['vistaro.images.disk' => 'public']);
        Storage::fake('public');

        $r = $this->actingAsAdmin()->post('/api/v1/admin/categories', ['name' => 'Pics', 'image' => $this->upload('a.png')], ['Accept' => 'application/json'])->assertCreated();
        $path = Category::findOrFail($r->json('data.id'))->image_path;
        $this->assertStringStartsWith('categories/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith($path, $r->json('data.image'));

        $this->actingAsAdmin()->post("/api/v1/admin/categories/{$r->json('data.id')}", ['_method' => 'PATCH', 'image' => $this->upload('b.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($path);                       // the old file is removed

        $this->actingAsAdmin()->post('/api/v1/admin/categories', ['name' => 'Bad', 'image' => $this->upload('x.png', 'not really an image')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['image']]);
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Url', 'image_url' => 'http://insecure.example/a.png'])->assertStatus(422);
        $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Url', 'image_url' => 'https://example.com/a.png'])->assertCreated()->assertJsonPath('data.image', 'https://example.com/a.png');
    }

    // -------------------------------------------------------- sub-categories

    public function test_sub_category_crud_and_relationship_rules(): void
    {
        $cat = Category::factory()->create();

        $r = $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['name' => 'Polos', 'category_id' => $cat->id])
            ->assertCreated()->assertJsonPath('data.slug', 'polos')->assertJsonPath('data.category.id', $cat->id);
        $id = $r->json('data.id');

        $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['name' => 'Orphan', 'category_id' => 99999])->assertStatus(422)->assertJsonStructure(['errors' => ['category_id']]);
        $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['name' => 'NoCat'])->assertStatus(422);
        $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['name' => 'Dup', 'slug' => 'polos', 'category_id' => $cat->id])->assertStatus(422);

        $other = Category::factory()->create();
        $this->actingAsAdmin()->patchJson("/api/v1/admin/sub-categories/{$id}", ['category_id' => $other->id, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.category.id', $other->id)->assertJsonPath('data.is_active', false);

        $this->actingAsAdmin()->getJson("/api/v1/admin/sub-categories?category_id={$other->id}")->assertJsonPath('meta.total', 1);
        $this->actingAsAdmin()->deleteJson("/api/v1/admin/sub-categories/{$id}")->assertOk();
        $this->assertDatabaseMissing('sub_categories', ['id' => $id]);
    }

    public function test_sub_category_with_products_cannot_be_deleted(): void
    {
        $p = $this->makeProduct();

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/sub-categories/{$p->sub_category_id}")->assertStatus(409);
        $this->assertDatabaseHas('sub_categories', ['id' => $p->sub_category_id]);
    }

    // --------------------------------------------------------- product create

    public function test_product_is_created_with_colours_sizes_stock_and_derived_fields(): void
    {
        $payload = $this->productPayload([
            'name' => 'Linen Resort Shirt', 'description' => 'Light.', 'price' => 1299.5, 'mrp' => 1999,
            'attributes' => ['Fabric' => ' Linen ', 'fit' => 'Regular', 'pattern' => '', 'weight' => 180],
            'is_bestseller' => true, 'discount_percent' => 99,
            'colors' => [['name' => 'Black', 'hex' => '#111111'], ['name' => 'Stone', 'hex' => '#b9b2a3']],
            'sizes' => [['size' => 'S', 'quantity' => 5, 'offline_quantity' => 2], ['size' => 'M', 'quantity' => 12], ['size' => '3XL', 'quantity' => 0]],
        ]);

        $r = $this->actingAsAdmin()->postJson('/api/v1/admin/products', $payload)->assertCreated()
            ->assertJsonPath('data.slug', 'linen-resort-shirt')->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.price', '1299.50')->assertJsonPath('data.mrp', '1999.00')
            ->assertJsonPath('data.discount_percent', 35)                       // derived; the client's 99 is ignored
            ->assertJsonPath('data.attributes', ['fabric' => 'Linen', 'fit' => 'Regular', 'weight' => '180'])   // trimmed, lower-cased, empties dropped
            ->assertJsonPath('data.stock.online.total', 17)->assertJsonPath('data.stock.online.reserved', 0)
            ->assertJsonPath('data.stock.offline.total', 2)
            ->assertJsonCount(2, 'data.colors')->assertJsonPath('data.colors.1.hex', '#B9B2A3');

        $p = Product::findOrFail($r->json('data.id'));
        $this->assertSame($p->subCategory->category_id, $p->category_id, 'category derived from the sub-category');
        $this->assertSame(['S', 'M', '3XL'], $p->sizes->map(fn ($s) => $s->size->value)->all());
        $this->assertSame('VS-TST-001-S', $p->sizes->first()->sku);
        $this->assertSame(3, OfflineStock::where('product_id', $p->id)->count(), 'every size has an offline row');
        $this->assertSame(['initial', 'initial', 'initial'], InventoryMovement::pluck('type')->map->value->all());
        $this->assertSame('admin', InventoryMovement::first()->actor_type);
        $this->assertSame(['online', 'offline', 'online'], InventoryMovement::pluck('location')->map->value->all());
    }

    public function test_product_creation_is_validated(): void
    {
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', [])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sub_category_id', 'name', 'sku', 'price', 'mrp']]);

        $bad = [
            'mrp below price' => ['price' => 1000, 'mrp' => 900],
            'zero price' => ['price' => 0],
            'negative price' => ['price' => -5],
            'three decimals' => ['price' => 10.123, 'mrp' => 20],
            'unknown sub-category' => ['sub_category_id' => 99999],
            'bad status' => ['status' => 'deleted'],
            'bad sku' => ['sku' => 'has space!'],
            'bad currency' => ['currency' => 'USD'],
            'attribute key' => ['attributes' => ['Bad Key!' => 'x']],
            'attribute value' => ['attributes' => ['fit' => str_repeat('a', 101)]],
            'attribute nested' => ['attributes' => ['fit' => ['a']]],
            'too many attributes' => ['attributes' => array_combine(array_map(fn ($i) => "k{$i}", range(1, 21)), range(1, 21))],
            'unknown size' => ['sizes' => [['size' => '5XL']]],
            'duplicate size' => ['sizes' => [['size' => 'M'], ['size' => 'M']]],
            'negative stock' => ['sizes' => [['size' => 'M', 'quantity' => -1]]],
            'colour hex' => ['colors' => [['name' => 'Red', 'hex' => 'red']]],
            'duplicate colour' => ['colors' => [['name' => 'Red', 'hex' => '#FF0000'], ['name' => 'red', 'hex' => '#FF0001']]],
        ];
        foreach ($bad as $label => $override) {
            $this->actingAsAdmin()->postJson('/api/v1/admin/products', $this->productPayload($override))->assertStatus(422, $label);
        }
        $this->assertSame(0, Product::count(), 'nothing was created by any invalid request');
    }

    public function test_sku_and_slug_must_be_unique(): void
    {
        $existing = $this->makeProduct();

        $this->actingAsAdmin()->postJson('/api/v1/admin/products', $this->productPayload(['sku' => $existing->sku]))->assertStatus(422)->assertJsonStructure(['errors' => ['sku']]);
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', $this->productPayload(['sku' => 'NEW-1', 'slug' => $existing->slug]))->assertStatus(422)->assertJsonStructure(['errors' => ['slug']]);
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', $this->productPayload(['sku' => 'NEW-2', 'name' => $existing->name, 'slug' => null]))->assertCreated();
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', $this->productPayload(['sku' => 'NEW-3', 'name' => $existing->name]))->assertCreated();
        $slugs = Product::pluck('slug');
        $this->assertSame($slugs->count(), $slugs->unique()->count());
    }

    public function test_a_failed_creation_rolls_back_completely(): void
    {
        $payload = $this->productPayload(['sizes' => [['size' => 'M', 'quantity' => 5]]]);
        // fail AFTER the product row is written: the stock movement insert blows up
        DB::listen(fn ($q) => null);
        InventoryMovement::creating(fn () => throw new \RuntimeException('boom'));

        $this->actingAsAdmin()->postJson('/api/v1/admin/products', $payload)->assertStatus(500);

        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductSize::count());
        $this->assertSame(0, OfflineStock::count());
    }

    // --------------------------------------------------------- product update

    public function test_product_update_is_partial_and_keeps_derived_data_consistent(): void
    {
        $p = $this->makeProduct([], ['price' => 1000, 'mrp' => 2000]);
        $other = SubCategory::factory()->create();

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['name' => 'Renamed'])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.slug', $p->slug)->assertJsonPath('data.price', '1000.00');

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['price' => 1500])->assertOk()->assertJsonPath('data.discount_percent', 25);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['price' => 2500])->assertStatus(422)->assertJsonStructure(['errors' => ['mrp']]);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['mrp' => 1000])->assertStatus(422);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['price' => 2500, 'mrp' => 3000])->assertOk()->assertJsonPath('data.discount_percent', 17);

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['sub_category_id' => $other->id])->assertOk()
            ->assertJsonPath('data.category_id', $other->category_id)->assertJsonPath('data.sub_category.id', $other->id);

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['attributes' => ['fit' => 'Slim']])->assertOk()->assertJsonPath('data.attributes', ['fit' => 'Slim']);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['attributes' => []])->assertOk();
        $this->assertNull($p->fresh()->attributes);

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['sku' => Product::factory()->create()->sku])->assertStatus(422);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['sku' => $p->sku])->assertOk();
        // colours/sizes have their own endpoints: a non-empty list on PATCH is refused
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['colors' => [['name' => 'Red', 'hex' => '#FF0000']]])->assertStatus(422);
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['sizes' => [['size' => 'M', 'quantity' => 99]]])->assertStatus(422);
        $this->assertSame(0, $p->colors()->count());
        $this->actingAsAdmin()->patchJson('/api/v1/admin/products/99999', ['name' => 'x'])->assertNotFound();
    }

    public function test_status_and_availability_toggles_control_public_visibility_and_stay_independent_for_offline(): void
    {
        $p = $this->makeProduct(['M' => 5]);
        $this->getJson("/api/v1/products/{$p->slug}")->assertOk();

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['is_available' => false])->assertOk();
        $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertNotFound();
        $this->assertTrue($p->fresh()->is_offline_available, 'online switch does not touch the shop flag');

        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['is_available' => true, 'is_offline_available' => false])->assertOk();
        $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertOk();

        foreach (['draft', 'archived'] as $status) {
            $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['status' => $status])->assertOk();
            $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertNotFound();
        }
    }

    // ----------------------------------------------------------------- delete

    public function test_an_unsold_product_is_really_deleted_with_its_children(): void
    {
        config(['vistaro.images.disk' => 'public']);
        Storage::fake('public');
        $p = $this->makeProduct(['M' => 5, 'L' => 3], [], [['Black', '#111111']]);
        $this->actingAsAdmin()->post("/api/v1/admin/products/{$p->id}/images", ['images' => [$this->upload('a.png')]], ['Accept' => 'application/json'])->assertCreated();
        $file = $p->images()->first()->path;

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/products/{$p->id}")->assertOk()->assertJsonPath('data.result', 'deleted');

        $this->assertDatabaseMissing('products', ['id' => $p->id]);
        $this->assertSame([0, 0, 0, 0], [ProductSize::count(), OfflineStock::count(), ProductColor::count(), $p->images()->count()]);
        Storage::disk('public')->assertMissing($file);
    }

    public function test_a_product_with_order_history_is_archived_not_deleted(): void
    {
        $p = $this->makeProduct(['M' => 5]);
        OrderItem::factory()->create(['order_id' => Order::factory()->create()->id, 'product_id' => $p->id, 'product_size_id' => $p->sizes->first()->id]);

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/products/{$p->id}")->assertOk()
            ->assertJsonPath('data.result', 'archived')->assertJsonPath('success', true);

        $fresh = $p->fresh();
        $this->assertSame('archived', $fresh->status->value);
        $this->assertFalse($fresh->is_available);
        $this->assertSame(1, OrderItem::where('product_id', $p->id)->count(), 'the order snapshot still points at the product');
        $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertNotFound();
    }

    // ------------------------------------------------------------ admin index

    public function test_admin_product_list_filters_sorts_and_reports_stock_without_n_plus_one(): void
    {
        $sub = SubCategory::factory()->create();
        $plenty = $this->makeProduct(['S' => 20, 'M' => 30], ['sub_category_id' => $sub->id, 'name' => 'Plenty Tee', 'sku' => 'AAA-1']);
        $low = $this->makeProduct(['S' => 3, 'M' => 30], ['name' => 'Low Tee', 'sku' => 'BBB-1']);
        $out = $this->makeProduct(['S' => 0, 'M' => 0], ['name' => 'Gone Tee', 'sku' => 'CCC-1']);
        $draft = $this->makeProduct(['M' => 9], ['name' => 'Draft Tee', 'sku' => 'DDD-1', 'status' => 'draft', 'is_available' => false]);

        $list = fn (string $qs = '') => $this->actingAsAdmin()->getJson('/api/v1/admin/products' . $qs);
        $names = fn ($r) => collect($r->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Draft Tee', 'Gone Tee', 'Low Tee', 'Plenty Tee'], $names($list()), 'admin sees drafts too');
        $this->assertSame(['Draft Tee', 'Plenty Tee'], $names($list('?stock_status=in')), 'every size at 5 or more');
        $this->assertSame(['Low Tee'], $names($list('?stock_status=low')), 'some stock, but a size below 5');
        $this->assertSame(['Gone Tee'], $names($list('?stock_status=out')));
        $this->assertSame(['Draft Tee'], $names($list('?status=draft')));
        $this->assertSame(['Draft Tee', 'Gone Tee', 'Low Tee', 'Plenty Tee'], $names($list('?q=tee')));
        $this->assertSame(['Low Tee'], $names($list('?q=BBB')));
        $this->assertSame(['Plenty Tee'], $names($list("?sub_category_id={$sub->id}")));
        $this->assertSame(['Draft Tee'], $names($list('?is_available=0')));

        $byStock = collect($list('?sort=stock&direction=desc&status=active')->json('data'))->pluck('name')->all();
        $this->assertSame(['Plenty Tee', 'Low Tee', 'Gone Tee'], $byStock);

        $row = collect($list('?q=Low')->json('data'))->first();
        $this->assertSame(['total' => 33, 'reserved' => 0, 'status' => 'low', 'low_stock' => true, 'out_of_stock' => false], collect($row['stock']['online'])->except('sizes')->all());
        $this->assertSame(['S', 'M'], collect($row['stock']['online']['sizes'])->pluck('size')->all());
        $this->assertSame(['low', 'ok'], collect($row['stock']['online']['sizes'])->pluck('status')->all());

        $count = function () use ($list) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $list('?per_page=100')->assertOk();

            return count(DB::getQueryLog());
        };
        foreach (range(1, 12) as $i) {
            $this->makeProduct(['M' => 5]);
        }
        $this->assertLessThanOrEqual(12, $count(), 'constant query count regardless of the number of products');
    }

    public function test_admin_product_list_pagination_limits(): void
    {
        Product::factory()->count(25)->create();

        $this->actingAsAdmin()->getJson('/api/v1/admin/products')->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 25);
        $this->actingAsAdmin()->getJson('/api/v1/admin/products?per_page=20&page=2')->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 2);
        $this->actingAsAdmin()->getJson('/api/v1/admin/products?per_page=101')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/products?sort=hacked')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/products?status=nonsense')->assertStatus(422);
    }
}
