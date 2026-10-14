<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductStatus;
use App\Enums\Size;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\SubCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): void
    {
        $this->seed(DatabaseSeeder::class);   // 3 categories, 10 sub-categories, 32 products (local/testing only)
    }

    private function product(array $attrs = [], array $sizes = ['M' => 20]): Product
    {
        $sub = isset($attrs['sub_category_id']) ? SubCategory::find($attrs['sub_category_id']) : SubCategory::factory()->create();
        $p = Product::factory()->create(['sub_category_id' => $sub->id] + $attrs);
        foreach ($sizes as $size => $qty) {
            ProductSize::factory()->for($p)->size(Size::from((string) $size))->quantity($qty)->create();
        }

        return $p;
    }

    // ------------------------------------------------------------- categories

    public function test_categories_list_nested_active_subcategories_with_visible_product_counts(): void
    {
        $this->seedCatalog();

        $r = $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('success', true);
        $cats = collect($r->json('data'));

        $this->assertSame(['topwear', 'bottomwear', 'outerwear'], $cats->pluck('slug')->all(), 'ordered by sort_order');
        $top = $cats->firstWhere('slug', 'topwear');
        $this->assertSame(8, $top['products_count']);
        $this->assertSame(['t-shirts' => 4, 'shirts' => 4], collect($top['sub_categories'])->pluck('products_count', 'slug')->all());
        $this->assertArrayNotHasKey('is_active', $top, 'admin-only field is not exposed publicly');
    }

    public function test_inactive_and_draft_data_is_never_public(): void
    {
        $this->seedCatalog();
        Product::where('slug', 'essential-crew-tee-1')->update(['status' => ProductStatus::Draft->value]);
        Product::where('slug', 'heavyweight-pocket-tee-2')->update(['is_available' => false]);
        SubCategory::where('slug', 'jeans')->update(['is_active' => false]);
        Category::where('slug', 'outerwear')->update(['is_active' => false]);

        $cats = collect($this->getJson('/api/v1/categories')->json('data'));
        $this->assertSame(['topwear', 'bottomwear'], $cats->pluck('slug')->all());
        $this->assertSame(['t-shirts' => 2, 'shirts' => 4], collect($cats->firstWhere('slug', 'topwear')['sub_categories'])->pluck('products_count', 'slug')->all());
        $this->assertSame(['trousers'], collect($cats->firstWhere('slug', 'bottomwear')['sub_categories'])->pluck('slug')->all());

        $this->getJson('/api/v1/categories/outerwear')->assertNotFound()->assertJsonPath('success', false);
        $subs = collect($this->getJson('/api/v1/sub-categories')->json('data'))->pluck('slug');
        $this->assertFalse($subs->contains('jeans'));
        $this->assertFalse($subs->contains('hoodies'), 'sub-categories of an inactive category are hidden too');
    }

    public function test_category_show_and_subcategory_filter(): void
    {
        $this->seedCatalog();

        $this->getJson('/api/v1/categories/bottomwear')->assertOk()
            ->assertJsonPath('data.name', 'Bottomwear')->assertJsonCount(2, 'data.sub_categories');
        $this->getJson('/api/v1/categories/nope')->assertNotFound();

        $this->getJson('/api/v1/sub-categories')->assertOk()->assertJsonCount(10, 'data');
        $r = $this->getJson('/api/v1/sub-categories?category=topwear')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('topwear', $r->json('data.0.category.slug'));
    }

    // ----------------------------------------------------------------- listing

    public function test_listing_defaults_to_10_supports_20_and_enforces_the_maximum(): void
    {
        $this->seedCatalog();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 32)->assertJsonPath('meta.last_page', 4)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 10);
        $this->getJson('/api/v1/products?per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonCount(32, 'data');
        $this->getJson('/api/v1/products?per_page=101')->assertStatus(422)->assertJsonStructure(['errors' => ['per_page']]);
        $this->getJson('/api/v1/products?per_page=0')->assertStatus(422);
        $this->getJson('/api/v1/products?page=0')->assertStatus(422);
        $this->getJson('/api/v1/products?page=99')->assertOk()->assertJsonCount(0, 'data');         // empty page, not an error
    }

    public function test_pages_never_duplicate_or_skip_rows(): void
    {
        $this->seedCatalog();

        foreach (['featured', 'newest', 'price_asc', 'price_desc', 'best'] as $sort) {
            $ids = [];
            foreach ([1, 2, 3, 4] as $page) {
                $ids = array_merge($ids, collect($this->getJson("/api/v1/products?sort={$sort}&page={$page}")->json('data'))->pluck('id')->all());
            }
            $this->assertCount(32, $ids, $sort);
            $this->assertCount(32, array_unique($ids), "{$sort}: duplicates across pages");
        }
    }

    public function test_listing_only_returns_publicly_visible_products(): void
    {
        $this->seedCatalog();
        Product::whereIn('id', [1, 2])->update(['status' => 'draft']);
        Product::where('id', 3)->update(['status' => 'archived']);
        Product::where('id', 4)->update(['is_available' => false]);

        $this->getJson('/api/v1/products?per_page=100')->assertJsonPath('meta.total', 28);
        foreach ([1, 2, 3, 4] as $id) {
            $slug = Product::find($id)->slug;
            $this->getJson("/api/v1/products/{$slug}")->assertNotFound();
            $this->getJson("/api/v1/products/{$id}")->assertNotFound();
        }
    }

    public function test_search_and_category_filters(): void
    {
        $this->seedCatalog();

        $this->assertSame(['Oxford Button-Down'], collect($this->getJson('/api/v1/products?q=oxford')->json('data'))->pluck('name')->all());
        $this->getJson('/api/v1/products?q=zzzz')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?category=topwear')->assertJsonPath('meta.total', 8);
        $this->getJson('/api/v1/products?category=outerwear&per_page=100')->assertJsonPath('meta.total', 18);
        $this->getJson('/api/v1/products?sub_category=jeans')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/products?category=topwear&sub_category=jeans')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?sub_category=unknown')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_attribute_colour_size_price_and_badge_filters(): void
    {
        $this->seedCatalog();
        $all = Product::with(['colors', 'sizes'])->get();

        // dynamic JSON attributes (no per-attribute column)
        $expected = $all->filter(fn ($p) => $p->attributes['fabric'] === 'Cotton')->count();
        $this->getJson('/api/v1/products?fabric=Cotton&per_page=100')->assertJsonPath('meta.total', $expected);
        $both = $all->filter(fn ($p) => in_array($p->attributes['fit'], ['Regular', 'Slim'], true))->count();
        $this->getJson('/api/v1/products?fit[]=Regular&fit[]=Slim&per_page=100')->assertJsonPath('meta.total', $both);
        $this->getJson('/api/v1/products?pattern=Striped&per_page=100')->assertJsonPath('meta.total', $all->filter(fn ($p) => $p->attributes['pattern'] === 'Striped')->count());

        $black = $all->filter(fn ($p) => $p->colors->contains('name', 'Black'))->count();
        $this->getJson('/api/v1/products?color=Black&per_page=100')->assertJsonPath('meta.total', $black);

        // size filter = in stock in that size
        $xl = $all->filter(fn ($p) => $p->sizes->contains(fn ($s) => $s->size === Size::XL && $s->quantity > 0))->count();
        $r = $this->getJson('/api/v1/products?size=XL&per_page=100')->assertJsonPath('meta.total', $xl);
        foreach ($r->json('data') as $item) {
            $this->assertTrue(collect($item['sizes'])->contains(fn ($s) => $s['size'] === 'XL' && $s['available']));
        }

        $range = $all->filter(fn ($p) => (float) $p->price >= 1299 && (float) $p->price <= 2499)->count();
        $this->getJson('/api/v1/products?price_min=1299&price_max=2499&per_page=100')->assertJsonPath('meta.total', $range);

        $this->getJson('/api/v1/products?badge=new&per_page=100')->assertJsonPath('meta.total', $all->where('is_new_arrival', true)->count());
        $this->getJson('/api/v1/products?badge=best&per_page=100')->assertJsonPath('meta.total', $all->where('is_bestseller', true)->count());
        $sale = $all->filter(fn ($p) => $p->discount_percent >= 25)->count();
        $this->getJson('/api/v1/products?badge=sale&per_page=100')->assertJsonPath('meta.total', $sale);

        // filters combine, and the same filter is applied on every page
        $combined = $this->getJson('/api/v1/products?category=outerwear&fabric=Cotton')->json('meta.total');
        $viaPages = 0;
        foreach ([1, 2, 3] as $page) {
            $viaPages += count($this->getJson("/api/v1/products?category=outerwear&fabric=Cotton&per_page=10&page={$page}")->json('data'));
        }
        $this->assertSame($combined, $viaPages);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->seedCatalog();

        foreach (['size=5XL', 'size[]=M&size[]=bogus', 'price_min=-1', 'price_min=500&price_max=100', 'price_min=abc', 'sort=hacked', 'badge=weird', 'direction=sideways'] as $qs) {
            $this->getJson("/api/v1/products?{$qs}")->assertStatus(422)->assertJsonPath('success', false);
        }
        $this->getJson('/api/v1/products?badge=trending')->assertStatus(422)->assertJsonStructure(['errors' => ['badge']]);
        $this->getJson('/api/v1/products?q=' . str_repeat('a', 101))->assertStatus(422);
    }

    public function test_sorting(): void
    {
        $this->seedCatalog();
        $get = fn (string $s) => collect($this->getJson("/api/v1/products?sort={$s}&per_page=100")->json('data'));

        $asc = $get('price_asc')->pluck('price')->map(fn ($v) => (float) $v);
        $this->assertSame($asc->sort()->values()->all(), $asc->values()->all());
        $desc = $get('price_desc')->pluck('price')->map(fn ($v) => (float) $v);
        $this->assertSame($desc->sortDesc()->values()->all(), $desc->values()->all());
        $this->assertSame(range(32, 1), $get('newest')->pluck('id')->all());
        $this->assertSame(range(1, 32), $get('featured')->pluck('id')->all());

        $best = $get('best');
        $flags = $best->pluck('badges.bestseller')->all();
        $this->assertSame(collect($flags)->sortDesc()->values()->all(), $flags, 'bestsellers first');
    }

    public function test_discount_is_derived_from_price_and_mrp_never_from_the_client(): void
    {
        $this->seedCatalog();
        $p = Product::find(1);

        $this->getJson("/api/v1/products/{$p->slug}?discount_percent=99&price=1")->assertOk()
            ->assertJsonPath('data.discount_percent', $p->discount_percent)->assertJsonPath('data.price', $p->price);

        $p->update(['price' => 1000, 'mrp' => 2000]);
        $this->getJson("/api/v1/products/{$p->slug}")->assertJsonPath('data.discount_percent', 50)->assertJsonPath('data.badges.sale', true);
    }

    // ------------------------------------------------------------------ detail

    public function test_detail_by_slug_or_id_with_images_colours_sizes_and_attributes(): void
    {
        $this->seedCatalog();

        $r = $this->getJson('/api/v1/products/essential-crew-tee-1')->assertOk()
            ->assertJsonPath('data.id', 1)->assertJsonPath('data.sku', 'VS-TSH-001')
            ->assertJsonPath('data.category.slug', 'topwear')->assertJsonPath('data.sub_category.slug', 't-shirts')
            ->assertJsonPath('data.attributes.fabric', 'Cotton')->assertJsonCount(4, 'data.images')->assertJsonCount(3, 'data.colors');
        $this->assertStringStartsWith('https://', $r->json('data.images.0.url'));
        $this->assertTrue($r->json('data.images.0.is_primary'));
        $this->assertNotEmpty($r->json('data.description'));
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL', 'XXL'], collect($r->json('data.sizes'))->pluck('size')->all());

        $this->getJson('/api/v1/products/1')->assertOk()->assertJsonPath('data.slug', 'essential-crew-tee-1');
        $this->getJson('/api/v1/products/does-not-exist')->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_local_disk_images_resolve_to_storage_urls(): void
    {
        $p = $this->product();
        ProductImage::factory()->for($p)->create(['disk' => 'public', 'path' => 'products/1/a.jpg', 'is_primary' => true]);

        $url = $this->getJson("/api/v1/products/{$p->slug}")->json('data.images.0.url');
        $this->assertStringEndsWith('/storage/products/1/a.jpg', $url);
    }

    public function test_storefront_stock_display_rules(): void
    {
        $p = $this->product([], ['XS' => 0, 'S' => 1, 'M' => 8, 'L' => 10, 'XL' => 11, 'XXL' => 500, '3XL' => 9]);

        $r = $this->getJson("/api/v1/products/{$p->slug}")->assertOk();
        $sizes = collect($r->json('data.sizes'))->keyBy('size');

        $this->assertFalse($sizes['XS']['available'], 'zero stock => disabled');
        $this->assertNull($sizes['XS']['label']);
        $this->assertSame(['available' => true, 'left' => 1, 'label' => '1 left'], collect($sizes['S'])->only(['available', 'left', 'label'])->all());
        $this->assertSame('8 left', $sizes['M']['label']);
        $this->assertSame('10 left', $sizes['L']['label'], '10 is still shown exactly');
        $this->assertNull($sizes['XL']['label'], '11 is not');
        $this->assertTrue($sizes['XL']['available']);
        $this->assertNull($sizes['XXL']['left']);
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'], $sizes->keys()->all(), 'ordered XS..4XL');

        // exact quantities above 10 (and internal fields) never appear anywhere in the payload
        $json = $r->getContent();
        $this->assertStringNotContainsString('"quantity"', $json);
        $this->assertStringNotContainsString('500', $json);
        $this->assertStringNotContainsString('reserved', $json);
        $this->assertTrue($r->json('data.in_stock'));
    }

    public function test_product_with_no_stock_is_listed_but_flagged_out_of_stock(): void
    {
        $p = $this->product([], ['M' => 0, 'L' => 0]);

        $this->getJson("/api/v1/products/{$p->slug}")->assertOk()->assertJsonPath('data.in_stock', false);
        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 1);
    }

    public function test_product_without_attributes_returns_an_empty_object(): void
    {
        $p = $this->product(['attributes' => null]);

        $r = $this->getJson("/api/v1/products/{$p->slug}")->assertOk();
        $this->assertStringContainsString('"attributes":{}', $r->getContent());
    }

    // ----------------------------------------------------------------- related

    public function test_related_products_come_from_the_same_subcategory_then_category(): void
    {
        $this->seedCatalog();

        $r = $this->getJson('/api/v1/products/essential-crew-tee-1/related')->assertOk();
        $ids = collect($r->json('data'))->pluck('id');
        $this->assertFalse($ids->contains(1), 'never itself');
        $this->assertLessThanOrEqual(8, $ids->count());
        $this->assertSame([2, 3, 4], $ids->take(3)->all(), 'same sub-category first (bestseller, then id)');
        $this->assertGreaterThan(3, $ids->count(), 'topped up from the same category');
        $slugs = collect($r->json('data'))->pluck('sub_category.slug')->unique()->sort()->values()->all();
        $this->assertSame(['shirts', 't-shirts'], $slugs);

        Product::where('id', 2)->update(['status' => 'draft']);
        $this->assertFalse(collect($this->getJson('/api/v1/products/essential-crew-tee-1/related')->json('data'))->pluck('id')->contains(2));
        $this->getJson('/api/v1/products/nope/related')->assertNotFound();
    }

    // ------------------------------------------------------------------ facets

    public function test_facets_reflect_the_visible_catalogue(): void
    {
        $this->seedCatalog();
        $visible = Product::with(['colors', 'sizes'])->get();

        $r = $this->getJson('/api/v1/products/facets')->assertOk();
        $fits = collect($r->json('data.fits'))->pluck('count', 'value')->all();
        $expected = $visible->groupBy(fn ($p) => $p->attributes['fit'])->map->count()->all();
        ksort($fits);
        ksort($expected);
        $this->assertSame($expected, $fits);

        $this->assertSame(32, array_sum(collect($r->json('data.fabrics'))->pluck('count')->all()));
        $this->assertSame(32, array_sum(collect($r->json('data.patterns'))->pluck('count')->all()));
        $this->assertSame(['Black', 'Navy', 'Off White', 'Olive', 'Stone'], collect($r->json('data.colors'))->pluck('name')->all());
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL', 'XXL'], collect($r->json('data.sizes'))->pluck('size')->all());
        $this->assertEquals($visible->min('price'), $r->json('data.price.min'));
        $this->assertEquals($visible->max('price'), $r->json('data.price.max'));

        $scoped = $this->getJson('/api/v1/products/facets?sub_category=jeans')->assertOk();
        $this->assertSame(3, array_sum(collect($scoped->json('data.fabrics'))->pluck('count')->all()));

        Product::where('id', 1)->update(['status' => 'draft']);
        $after = collect($this->getJson('/api/v1/products/facets')->json('data.fabrics'))->sum('count');
        $this->assertSame(31, $after, 'hidden products do not appear in facets');
    }

    // ------------------------------------------------------------- performance

    public function test_listing_uses_a_constant_number_of_queries(): void
    {
        $this->seedCatalog();

        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();

            return count(DB::getQueryLog());
        };

        $ten = $count('/api/v1/products?per_page=10');
        $hundred = $count('/api/v1/products?per_page=100');
        $this->assertSame($ten, $hundred, 'no N+1: query count does not grow with page size');
        $this->assertLessThanOrEqual(8, $ten);
        $this->assertLessThanOrEqual(4, $count('/api/v1/categories'));
    }

    public function test_public_endpoints_need_no_authentication(): void
    {
        $this->seedCatalog();
        foreach (['/api/v1/categories', '/api/v1/sub-categories', '/api/v1/products', '/api/v1/products/facets', '/api/v1/products/1', '/api/v1/products/1/related'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }
}
