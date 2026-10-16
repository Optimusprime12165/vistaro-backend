<?php

namespace Tests\Feature\SharedStock;

use App\Enums\AdminRole;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * Channel visibility (Online / Offline / Both) and the out-of-stock rules.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=ChannelAndOutOfStockTest`.
 */
class ChannelAndOutOfStockTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private function channelProduct(string $channel, array $stock = ['M' => 5]): Product
    {
        return $this->makeProduct($stock, [
            'is_available' => in_array($channel, ['online', 'both'], true),
            'is_offline_available' => in_array($channel, ['offline', 'both'], true),
        ]);
    }

    // ------------------------------------------------------------------ channels

    public function test_offline_only_product_is_not_in_the_public_catalogue(): void
    {
        $offline = $this->channelProduct('offline');
        $online = $this->channelProduct('online');
        $both = $this->channelProduct('both');

        $slugs = collect($this->guest()->getJson('/api/v1/products?per_page=100')->assertOk()->json('data'))->pluck('slug')->all();

        $this->assertContains($online->slug, $slugs);
        $this->assertContains($both->slug, $slugs);
        $this->assertNotContains($offline->slug, $slugs);
        $this->guest()->getJson("/api/v1/products/{$offline->slug}")->assertStatus(404);
        $this->guest()->getJson("/api/v1/products/{$offline->slug}/related")->assertStatus(404);
    }

    public function test_offline_only_product_cannot_be_bought_online(): void
    {
        $offline = $this->channelProduct('offline');
        [$user, $address] = $this->shopper();

        $this->addToCart($user, $offline, 'M', 1)->assertStatus(422);
        $this->assertSame([5, 0], $this->stock($offline, 'M'));
    }

    public function test_switching_a_product_to_offline_while_it_is_in_a_cart_blocks_checkout(): void
    {
        $p = $this->channelProduct('both');
        [$user, $address] = $this->shopper();
        $this->addToCart($user, $p, 'M', 1)->assertSuccessful();

        $p->forceFill(['is_available' => false])->save();

        $this->placeOrder($user, $address)->assertStatus(422);
        $this->assertSame([5, 0], $this->stock($p, 'M'));
    }

    public function test_stocks_tabs_list_products_by_channel_and_both_appears_in_each(): void
    {
        $online = $this->channelProduct('online');
        $offline = $this->channelProduct('offline');
        $both = $this->channelProduct('both');

        $ids = fn (string $tab) => collect($this->actingAsAdmin(AdminRole::Staff)->getJson("/api/v1/admin/inventory?location={$tab}&per_page=100")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$online->id, $both->id], $ids('online'));
        $this->assertEqualsCanonicalizing([$offline->id, $both->id], $ids('offline'));
    }

    public function test_product_channel_is_set_from_one_field_and_reported_back(): void
    {
        $sub = SubCategory::factory()->create();
        $payload = ['sub_category_id' => $sub->id, 'name' => 'Channel Tee', 'sku' => 'CH-1', 'price' => '999', 'mrp' => '1299', 'channel' => 'offline', 'sizes' => [['size' => 'M', 'quantity' => 4]]];

        $res = $this->actingAsAdmin()->postJson('/api/v1/admin/products', $payload)->assertCreated();
        $this->assertSame('offline', $res->json('data.channel'));
        $this->assertFalse($res->json('data.is_available'));
        $this->assertTrue($res->json('data.is_offline_available'));
        $this->assertSame(4, $res->json('data.stock.sizes.0.quantity'));

        $id = $res->json('data.id');
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$id}", ['channel' => 'both'])->assertOk()->assertJsonPath('data.channel', 'both');
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$id}", ['channel' => 'sideways'])->assertStatus(422);
    }

    public function test_inactive_product_is_not_public(): void
    {
        $p = $this->channelProduct('online');
        $this->actingAsAdmin()->patchJson("/api/v1/admin/products/{$p->id}", ['status' => 'draft'])->assertOk();

        $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertStatus(404);
    }

    // ------------------------------------------------------------------ out of stock

    public function test_sold_out_product_stays_listed_and_is_flagged_out_of_stock(): void
    {
        $p = $this->channelProduct('online', ['M' => 0, 'L' => 0]);

        $list = collect($this->guest()->getJson('/api/v1/products?per_page=100')->assertOk()->json('data'))->firstWhere('slug', $p->slug);
        $this->assertNotNull($list, 'out-of-stock products are NOT hidden from the listing');
        $this->assertTrue($list['out_of_stock']);
        $this->assertFalse($list['in_stock']);

        $detail = $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertOk()->json('data');
        $this->assertTrue($detail['out_of_stock']);
        $this->assertSame([false, false], array_column($detail['sizes'], 'available'));
    }

    public function test_sold_out_size_is_unavailable_but_the_other_sizes_are_not(): void
    {
        $p = $this->channelProduct('online', ['M' => 0, 'L' => 3]);
        [$user, $address] = $this->shopper();

        $sizes = collect($this->guest()->getJson("/api/v1/products/{$p->slug}")->assertOk()->json('data.sizes'))->keyBy('size');
        $this->assertFalse($sizes['M']['available']);
        $this->assertTrue($sizes['L']['available']);
        $this->assertFalse($this->guest()->getJson("/api/v1/products/{$p->slug}")->json('data.out_of_stock'));

        $this->addToCart($user, $p, 'M', 1)->assertStatus(409);
        $this->addToCart($user, $p, 'L', 1)->assertSuccessful();
    }

    public function test_purchase_is_possible_again_after_restocking(): void
    {
        $p = $this->channelProduct('online', ['M' => 0]);
        [$user, $address] = $this->shopper();
        $this->addToCart($user, $p, 'M', 1)->assertStatus(409);

        $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/products/{$p->id}/stock/adjust", ['size' => 'M', 'delta' => 4, 'note' => 'new stock arrived'])->assertOk();

        $this->assertTrue($this->guest()->getJson("/api/v1/products/{$p->slug}")->json('data.sizes.0.available'));
        $this->addToCart($user, $p, 'M', 1)->assertSuccessful();
        $this->placeOrder($user, $address)->assertCreated();
        $this->assertSame([3, 1], $this->stock($p, 'M'));
    }

    public function test_checkout_refuses_a_zero_stock_line_even_if_it_was_in_the_cart(): void
    {
        $p = $this->channelProduct('online', ['M' => 2]);
        [$user, $address] = $this->shopper();
        $this->addToCart($user, $p, 'M', 2)->assertSuccessful();

        ProductSize::query()->where('product_id', $p->id)->update(['quantity' => 0]);

        $this->placeOrder($user, $address)->assertStatus(409);
        $this->assertSame([0, 0], $this->stock($p, 'M'));
    }
}
