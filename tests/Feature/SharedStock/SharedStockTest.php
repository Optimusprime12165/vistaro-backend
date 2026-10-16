<?php

namespace Tests\Feature\SharedStock;

use App\Enums\AdminRole;
use App\Models\Address;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * Shared stock: ONE quantity per product size serves online orders AND manual offline (shop) adjustments.
 * NOTE: written without a PHP runtime in the authoring environment; run `php artisan test --filter=SharedStockTest`.
 */
class SharedStockTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private function adjust(Product $p, string $size, int $delta, ?string $note = 'sold in the shop', AdminRole $role = AdminRole::Staff)
    {
        return $this->actingAsAdmin($role)->postJson("/api/v1/admin/products/{$p->id}/stock/adjust", array_filter(
            ['size' => $size, 'delta' => $delta, 'note' => $note], fn ($v) => $v !== null,
        ));
    }

    private function sell(User $user, Address $address, Product $p, string $size, int $qty)
    {
        $this->addToCart($user, $p, $size, $qty)->assertSuccessful();

        return $this->placeOrder($user, $address);
    }

    public function test_every_size_keeps_its_own_independent_quantity(): void
    {
        $p = $this->makeProduct(['S' => 10, 'M' => 15, 'L' => 8, 'XL' => 5]);
        [$user, $address] = $this->shopper();

        $this->sell($user, $address, $p, 'M', 4)->assertCreated();
        $this->adjust($p, 'M', -5)->assertOk();

        $this->assertSame(6, $this->stock($p, 'M')[0], '15 - 4 online - 5 offline');
        $this->assertSame(10, $this->stock($p, 'S')[0]);
        $this->assertSame(8, $this->stock($p, 'L')[0]);
        $this->assertSame(5, $this->stock($p, 'XL')[0]);
    }

    public function test_online_and_offline_see_the_same_remaining_stock(): void
    {
        $p = $this->makeProduct(['M' => 15]);
        [$user, $address] = $this->shopper();
        $this->sell($user, $address, $p, 'M', 4)->assertCreated();
        $this->adjust($p, 'M', -5)->assertOk();

        $online = $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/inventory?location=online')->assertOk()->json('data.0');
        $offline = $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/inventory?location=offline')->assertOk()->json('data.0');

        $this->assertSame($p->id, $online['id']);
        $this->assertSame($online['stock'], $offline['stock'], 'a Both product shows identical shared stock in both tabs');
        $this->assertSame(6, $online['stock']['sizes'][0]['quantity']);
        $this->assertSame(6, $online['stock']['total']);

        // the website sees the same figure ("6 left")
        $public = $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertOk()->json('data.sizes.0');
        $this->assertSame(6, $public['left']);
    }

    public function test_manual_offline_adjustment_cannot_take_stock_below_zero(): void
    {
        $p = $this->makeProduct(['M' => 6]);

        $this->adjust($p, 'M', -7)->assertStatus(409);

        $this->assertSame(6, $this->stock($p, 'M')[0]);
        $this->assertSame(0, InventoryMovement::query()->where('product_id', $p->id)->count(), 'a refused adjustment leaves no ledger entry');
    }

    public function test_offline_sale_leaves_less_stock_for_online_buyers(): void
    {
        $p = $this->makeProduct(['M' => 2]);
        [$user, $address] = $this->shopper();

        $this->adjust($p, 'M', -2)->assertOk();

        $this->addToCart($user, $p, 'M', 1)->assertStatus(409);                    // nothing left online either
        $this->assertSame(0, $this->stock($p, 'M')[0]);
    }

    public function test_online_order_cannot_take_what_the_shop_already_sold(): void
    {
        $p = $this->makeProduct(['M' => 3]);
        [$user, $address] = $this->shopper();
        $this->addToCart($user, $p, 'M', 3)->assertSuccessful();                     // in the cart, NOT reserved

        $this->adjust($p, 'M', -2)->assertOk();                                      // the shop sells 2 meanwhile

        $this->placeOrder($user, $address)->assertStatus(409);                      // checkout re-checks the latest shared quantity
        $this->assertSame([1, 0], $this->stock($p, 'M'));
    }

    public function test_adjustment_requires_a_reason_and_records_actor_and_source(): void
    {
        $p = $this->makeProduct(['M' => 10]);

        $this->adjust($p, 'M', -1, null)->assertStatus(422);

        $this->adjust($p, 'M', -2, '2 pieces sold at the shop')->assertOk();
        $m = InventoryMovement::query()->where('product_id', $p->id)->firstOrFail();
        $this->assertSame('offline', $m->location->value);
        $this->assertSame('adjustment', $m->type->value);
        $this->assertSame(-2, $m->quantity_change);
        $this->assertSame(8, $m->quantity_after);
        $this->assertSame('admin', $m->actor_type);
        $this->assertNotNull($m->actor_id);
        $this->assertSame('2 pieces sold at the shop', $m->note);
    }

    public function test_a_mistaken_adjustment_is_corrected_with_another_audited_movement(): void
    {
        $p = $this->makeProduct(['M' => 10]);

        $this->adjust($p, 'M', -3, 'sold in the shop')->assertOk();
        $this->adjust($p, 'M', 3, 'correction: entered by mistake')->assertOk();

        $this->assertSame(10, $this->stock($p, 'M')[0]);
        $this->assertSame([-3, 3], InventoryMovement::query()->where('product_id', $p->id)->orderBy('id')->pluck('quantity_change')->all());
    }

    public function test_unknown_size_or_zero_delta_is_rejected(): void
    {
        $p = $this->makeProduct(['M' => 10]);

        $this->adjust($p, 'XL', -1)->assertStatus(422);
        $this->adjust($p, 'M', 0)->assertStatus(422);
        $this->assertSame(10, $this->stock($p, 'M')[0]);
    }

    public function test_set_stock_is_absolute_shared_and_audited(): void
    {
        $p = $this->makeProduct(['M' => 10, 'L' => 4]);

        $this->actingAsAdmin(AdminRole::Staff)->putJson("/api/v1/admin/products/{$p->id}/stock", ['sizes' => ['M' => 7, 'L' => 4], 'note' => 'stock take'])->assertOk();

        $this->assertSame(7, $this->stock($p, 'M')[0]);
        $this->assertSame(1, InventoryMovement::query()->where('product_id', $p->id)->count(), 'unchanged sizes write no movement');
    }

    public function test_no_separate_offline_quantity_can_be_supplied(): void
    {
        $sub = \App\Models\SubCategory::factory()->create();
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', [
            'sub_category_id' => $sub->id, 'name' => 'Split Tee', 'sku' => 'SPLIT-1', 'price' => '999', 'mrp' => '1299',
            'sizes' => [['size' => 'M', 'quantity' => 5, 'offline_quantity' => 3]],
        ])->assertStatus(422);
    }

    public function test_cancelling_an_order_gives_the_units_back_exactly_once(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        [$user, $address] = $this->shopper();
        $id = $this->sell($user, $address, $p, 'M', 3)->assertCreated()->json('data.id');
        $this->adjust($p, 'M', -2)->assertOk();
        $this->assertSame([5, 3], $this->stock($p, 'M'));

        $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$id}/cancel", [])->assertOk();
        $this->assertSame([8, 0], $this->stock($p, 'M'));

        $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$id}/cancel", [])->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame([8, 0], $this->stock($p, 'M'), 'a second cancel must not restore stock again');
    }

    public function test_sold_shared_stock_never_exceeds_what_exists(): void
    {
        $p = $this->makeProduct(['M' => 5]);
        [$u1, $a1] = $this->shopper();
        [$u2, $a2] = $this->shopper();
        $this->addToCart($u1, $p, 'M', 3)->assertSuccessful();
        $this->addToCart($u2, $p, 'M', 3)->assertSuccessful();

        $this->placeOrder($u1, $a1)->assertCreated();
        $this->placeOrder($u2, $a2)->assertStatus(409);                              // 5 - 3 = 2 left, 3 requested
        $this->adjust($p, 'M', -3)->assertStatus(409);                               // the shop cannot take 3 of the 2 left either
        $this->adjust($p, 'M', -2)->assertOk();

        $this->assertSame([0, 3], $this->stock($p, 'M'));
        $this->assertSame(1, ProductSize::query()->where('product_id', $p->id)->count());
    }
}
