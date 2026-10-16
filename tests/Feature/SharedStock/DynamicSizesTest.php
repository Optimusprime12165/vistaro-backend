<?php

namespace Tests\Feature\SharedStock;

use App\Enums\AdminRole;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * Dynamic sizes: free-form codes, enable / disable without losing stock, safe removal.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=DynamicSizesTest`.
 */
class DynamicSizesTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private function sizesUrl(int $id): string
    {
        return "/api/v1/admin/products/{$id}/sizes";
    }

    public function test_sizes_are_not_limited_to_the_fixed_list(): void
    {
        $sub = SubCategory::factory()->create();
        $res = $this->actingAsAdmin()->postJson('/api/v1/admin/products', [
            'sub_category_id' => $sub->id, 'name' => 'Denim', 'sku' => 'DEN-1', 'price' => '1999', 'mrp' => '2499',
            'sizes' => [['size' => '28', 'quantity' => 3], ['size' => '30', 'quantity' => 5], ['size' => 'free size', 'quantity' => 2], ['size' => 'M', 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(['M', '28', '30', 'FREE SIZE'], array_column($res->json('data.stock.sizes'), 'size'), 'known sizes first, then numbers, then the rest');
        $this->assertSame(11, $res->json('data.stock.total'));
    }

    public function test_size_codes_are_validated_and_normalised(): void
    {
        $p = $this->makeProduct(['M' => 1]);

        $this->actingAsAdmin()->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => 'bad<script>']]])->assertStatus(422);
        $this->actingAsAdmin()->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => 'a-very-long-size-code-over-16']]])->assertStatus(422);
        $this->actingAsAdmin()->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => ' xl ', 'quantity' => 4]]])->assertOk();

        $this->assertSame(4, $this->stock($p, 'XL')[0]);
        $this->actingAsAdmin()->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => 'xl', 'quantity' => 9]]])->assertOk();
        $this->assertSame(1 + 1, ProductSize::query()->where('product_id', $p->id)->count(), 'xl and XL are one size');
    }

    public function test_disabling_a_size_keeps_its_stock_and_blocks_purchase(): void
    {
        $p = $this->makeProduct(['M' => 7, 'L' => 4]);
        [$user, $address] = $this->shopper();

        $this->actingAsAdmin()->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => 'M', 'is_enabled' => false]]])->assertOk();

        $this->assertSame(7, $this->stock($p, 'M')[0], 'quantity untouched');
        $this->addToCart($user, $p, 'M', 1)->assertStatus(422);

        $public = collect($this->guest()->getJson("/api/v1/products/{$p->slug}")->assertOk()->json('data.sizes'))->pluck('size')->all();
        $this->assertSame(['L'], $public, 'a disabled size is not offered on the website');

        $admin = $this->actingAsAdmin()->getJson("/api/v1/admin/products/{$p->id}")->assertOk()->json('data.stock');
        $this->assertSame(11, $admin['total']);
        $this->assertSame(4, $admin['sellable_total']);

        // enabling brings the saved quantity back
        $this->actingAsAdmin()->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => 'M', 'is_enabled' => true]]])->assertOk();
        $this->addToCart($user, $p, 'M', 2)->assertSuccessful();
        $this->assertSame(7, $this->stock($p, 'M')[0]);
    }

    public function test_a_size_disabled_after_it_was_carted_blocks_checkout(): void
    {
        $p = $this->makeProduct(['M' => 7]);
        [$user, $address] = $this->shopper();
        $this->addToCart($user, $p, 'M', 1)->assertSuccessful();

        ProductSize::query()->where('product_id', $p->id)->update(['is_enabled' => false]);

        $this->placeOrder($user, $address)->assertStatus(422);
        $this->assertSame([7, 0], $this->stock($p, 'M'));
    }

    public function test_a_disabled_size_can_still_be_adjusted_by_staff(): void
    {
        $p = $this->makeProduct(['M' => 7]);
        ProductSize::query()->where('product_id', $p->id)->update(['is_enabled' => false]);

        $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/products/{$p->id}/stock/adjust", ['size' => 'M', 'delta' => -1, 'note' => 'damaged piece'])->assertOk();
        $this->assertSame(6, $this->stock($p, 'M')[0]);
    }

    public function test_staff_cannot_manage_sizes(): void
    {
        $p = $this->makeProduct(['M' => 7]);

        $this->actingAsAdmin(AdminRole::Staff)->putJson($this->sizesUrl($p->id), ['sizes' => [['size' => 'L', 'quantity' => 1]]])->assertStatus(403);
        $this->actingAsAdmin(AdminRole::Staff)->deleteJson("/api/v1/admin/products/{$p->id}/sizes/M")->assertStatus(403);
    }

    public function test_a_size_with_stock_cannot_be_removed(): void
    {
        $p = $this->makeProduct(['M' => 7, 'L' => 0]);

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/products/{$p->id}/sizes/M")->assertStatus(409);
        $this->assertSame(7, $this->stock($p, 'M')[0]);

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/products/{$p->id}/sizes/L")->assertOk()->assertJsonPath('data.result', 'deleted');
        $this->assertSame(1, ProductSize::query()->where('product_id', $p->id)->count());
    }

    public function test_a_size_used_by_past_orders_is_disabled_not_deleted(): void
    {
        $p = $this->makeProduct(['M' => 3]);
        [$user, $address] = $this->shopper();
        $this->addToCart($user, $p, 'M', 3)->assertSuccessful();
        $orderId = $this->placeOrder($user, $address)->assertCreated()->json('data.id');
        $psId = ProductSize::query()->where('product_id', $p->id)->value('id');
        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$orderId}/transition", ['status' => 'cancelled', 'reason' => 'test'])->assertOk();   // reserved units return -> quantity 3
        $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/products/{$p->id}/stock/adjust", ['size' => 'M', 'delta' => -3, 'note' => 'clear out'])->assertOk();

        $res = $this->actingAsAdmin()->deleteJson("/api/v1/admin/products/{$p->id}/sizes/M")->assertOk();

        $this->assertSame('disabled', $res->json('data.result'));
        $row = ProductSize::query()->findOrFail($psId);
        $this->assertFalse($row->is_enabled);
        $this->assertNotNull(Order::findOrFail($orderId)->items()->where('product_size_id', $psId)->first(), 'order item still points at the size');
        $this->assertGreaterThan(0, InventoryMovement::query()->where('product_size_id', $psId)->count(), 'ledger kept');
    }
}
