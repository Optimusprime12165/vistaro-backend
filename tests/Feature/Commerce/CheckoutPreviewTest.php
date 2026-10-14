<?php

namespace Tests\Feature\Commerce;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class CheckoutPreviewTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private function preview(User $u, array $q)
    {
        return $this->actingAsCustomer($u)->getJson('/api/v1/checkout/preview?' . http_build_query($q));
    }

    public function test_requires_a_customer_token(): void
    {
        $this->guest()->getJson('/api/v1/checkout/preview')->assertUnauthorized();
        $this->actingAsAdmin()->getJson('/api/v1/checkout/preview')->assertUnauthorized();
    }

    public function test_address_and_payment_method_are_validated_and_scoped_to_the_customer(): void
    {
        [$u, $a] = $this->shopper();
        [, $theirs] = $this->shopper();
        $p = $this->makeProduct(['M' => 5]);
        $this->addToCart($u, $p, 'M');

        $this->preview($u, [])->assertStatus(422)->assertJsonStructure(['errors' => ['address_id']]);
        $this->preview($u, ['address_id' => 99999])->assertStatus(422);
        $this->preview($u, ['address_id' => $theirs->id])->assertStatus(422)->assertJsonStructure(['errors' => ['address_id']]);
        $this->preview($u, ['address_id' => $a->id, 'payment_method' => 'bitcoin'])->assertStatus(422);
        $this->preview($u, ['address_id' => $a->id, 'payment_method' => 'cod'])->assertOk();
    }

    public function test_an_empty_cart_cannot_be_previewed(): void
    {
        [$u, $a] = $this->shopper();

        $this->preview($u, ['address_id' => $a->id])->assertStatus(422)->assertJsonStructure(['errors' => ['cart']]);
    }

    public function test_preview_summarises_address_items_totals_and_payment(): void
    {
        [$u, $a] = $this->shopper();
        $p = $this->makeProduct(['M' => 5], ['price' => '750.00', 'mrp' => '1000.00']);
        $this->addToCart($u, $p, 'M', 2);

        $r = $this->preview($u, ['address_id' => $a->id, 'payment_method' => 'cod'])->assertOk()
            ->assertJsonPath('data.address.id', $a->id)->assertJsonPath('data.can_place_order', true)->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.payment.method', 'cod')->assertJsonPath('data.payment.is_mock', false);

        // 2 x 750 = 1500 (< 1999 so + 99 shipping); MRP 2000 => discount 500
        $this->assertSame(['1500.00', '2000.00', '500.00', '99.00', '1599.00'], collect($r->json('data.totals'))->only(['subtotal', 'mrp_total', 'discount_total', 'shipping_fee', 'total'])->values()->all());
        $this->assertStringContainsString('cash', $r->json('data.payment.message'));
        $this->assertSame($r->json('data.totals'), $this->actingAsCustomer($u)->getJson('/api/v1/cart')->json('data.totals'), 'cart and checkout agree');
    }

    public function test_online_payment_preview_is_honest_about_the_mock_gateway(): void
    {
        [$u, $a] = $this->shopper();
        $this->addToCart($u, $this->makeProduct(['M' => 5]), 'M');

        foreach (['upi', 'card'] as $method) {
            $r = $this->preview($u, ['address_id' => $a->id, 'payment_method' => $method])->assertOk();
            $this->assertSame([$method, 'pending', true], [$r->json('data.payment.method'), $r->json('data.payment.status'), $r->json('data.payment.is_mock')]);
            $this->assertStringContainsString('no real payment', $r->json('data.payment.message'));
        }
    }

    public function test_mock_online_payment_is_refused_in_production_but_cod_still_works(): void
    {
        [$u, $a] = $this->shopper();
        $this->addToCart($u, $this->makeProduct(['M' => 5]), 'M');
        $this->app['env'] = 'production';

        $this->preview($u, ['address_id' => $a->id, 'payment_method' => 'upi'])->assertStatus(422)->assertJsonStructure(['errors' => ['payment_method']]);
        $this->preview($u, ['address_id' => $a->id, 'payment_method' => 'card'])->assertStatus(422);
        $this->preview($u, ['address_id' => $a->id, 'payment_method' => 'cod'])->assertOk();
    }

    public function test_changed_price_and_stock_show_up_without_creating_anything(): void
    {
        [$u, $a] = $this->shopper();
        $p = $this->makeProduct(['M' => 5], ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($u, $p, 'M', 3);
        $q = ['address_id' => $a->id];

        $this->assertSame('3000.00', $this->preview($u, $q)->json('data.totals.total'), '3 x 1000 qualifies for free shipping');

        $p->update(['price' => 500]);
        $this->assertSame('1599.00', $this->preview($u, $q)->json('data.totals.total'), '3 x 500 = 1500 + 99 shipping');

        ProductSize::where('product_id', $p->id)->update(['quantity' => 2]);
        $r = $this->preview($u, $q)->assertOk();
        $this->assertFalse($r->json('data.can_place_order'));
        $this->assertSame('insufficient_stock', $r->json('data.issues.0.code'));
        $this->assertSame('Only 2 left in size M.', $r->json('data.issues.0.message'));

        $p->update(['status' => 'draft']);
        $this->assertSame('product_unavailable', $this->preview($u, $q)->json('data.issues.0.code'));

        // a preview is read-only
        $this->assertSame([0, 0, 0], [Order::count(), Payment::count(), InventoryMovement::count()]);
        $this->assertSame([2, 0], $this->stock($p, 'M'));
        $this->assertSame(3, CartItem::first()->quantity);
    }
}
