<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class OrderCancelReturnTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private User $user;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->user, $this->address] = $this->shopper();
    }

    /** @return array{0:int,1:Product} id of a freshly placed order and its product */
    private function placed(int $qty = 2): array
    {
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($this->user, $p, 'M', $qty);

        return [$this->placeOrder($this->user, $this->address)->assertCreated()->json('data.id'), $p];
    }

    private function me(): static
    {
        return $this->actingAsCustomer($this->user);
    }

    // ------------------------------------------------------------------ cancel

    public function test_cancelling_releases_the_reserved_stock_and_records_everything(): void
    {
        [$id, $p] = $this->placed(3);
        $this->assertSame([7, 3], $this->stock($p, 'M'));

        $r = $this->me()->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Ordered by mistake'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.payment_status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false)->assertJsonPath('data.payment.status', 'cancelled');

        $this->assertSame([10, 0], $this->stock($p, 'M'), 'reserved units are available again');
        $last = InventoryMovement::latest('id')->first();
        $this->assertSame(['order_released', 3, -3, 10, $id], [$last->type->value, $last->quantity_change, $last->reserved_change, $last->quantity_after, $last->reference_id]);
        $this->assertSame('user', $last->actor_type);

        $h = collect($r->json('data.history'));
        $this->assertSame(['pending', 'cancelled'], $h->pluck('status')->all());
        $this->assertSame([null, 'pending'], $h->pluck('from')->all());
        $this->assertSame('Ordered by mistake', $h->last()['reason']);
        $this->assertStringContainsString('cancelled before any payment', $r->json('data.payment.message'));
    }

    public function test_cancel_reason_is_optional_and_has_a_default(): void
    {
        [$id] = $this->placed();

        $r = $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertOk();
        $this->assertSame('Cancelled by customer', collect($r->json('data.history'))->last()['reason']);
    }

    public function test_an_order_cannot_be_cancelled_twice_or_once_it_has_moved_on(): void
    {
        [$id, $p] = $this->placed();
        $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertOk();
        $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);
        $this->assertSame([10, 0], $this->stock($p, 'M'), 'a second cancel does not release stock twice');

        foreach (['packed', 'pickup_requested', 'shipped', 'out_for_delivery', 'delivered', 'return_requested', 'returned', 'refund_pending', 'refunded', 'delivery_failed'] as $status) {
            [$oid, $q] = $this->placed();
            Order::whereKey($oid)->update(['status' => $status]);
            $before = $this->stock($q, 'M');

            $this->me()->postJson("/api/v1/orders/{$oid}/cancel")->assertStatus(422, $status);
            $this->assertSame($before, $this->stock($q, 'M'), "{$status}: stock untouched");
            $this->assertSame($status, Order::find($oid)->status->value);
        }
    }

    public function test_pending_confirmed_and_processing_orders_can_be_cancelled(): void
    {
        foreach (['pending', 'confirmed', 'processing'] as $status) {
            [$id, $p] = $this->placed();
            Order::whereKey($id)->update(['status' => $status]);

            $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
            $this->assertSame([10, 0], $this->stock($p, 'M'));
        }
    }

    public function test_a_paid_order_goes_to_refund_pending_and_nothing_is_marked_refunded(): void
    {
        [$id] = $this->placed();
        Order::whereKey($id)->update(['payment_status' => 'paid']);
        \App\Models\Payment::query()->update(['status' => 'paid']);

        $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.payment_status', 'refund_pending')->assertJsonPath('data.payment.status', 'refund_pending');
    }

    public function test_cancelling_one_order_never_touches_another(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($this->user, $p, 'M', 2);
        $a = $this->placeOrder($this->user, $this->address)->json('data.id');
        $this->addToCart($this->user, $p, 'M', 3);
        $b = $this->placeOrder($this->user, $this->address)->json('data.id');
        $this->assertSame([5, 5], $this->stock($p, 'M'));

        $this->me()->postJson("/api/v1/orders/{$a}/cancel")->assertOk();

        $this->assertSame([7, 3], $this->stock($p, 'M'));
        $this->assertSame('pending', Order::find($b)->status->value);
    }

    public function test_multi_line_cancellation_releases_every_line(): void
    {
        $a = $this->makeProduct(['M' => 10]);
        $b = $this->makeProduct(['S' => 4]);
        $this->addToCart($this->user, $a, 'M', 4);
        $this->addToCart($this->user, $b, 'S', 4);
        $id = $this->placeOrder($this->user, $this->address)->json('data.id');
        $this->assertSame([[6, 4], [0, 4]], [$this->stock($a, 'M'), $this->stock($b, 'S')]);

        $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertOk();

        $this->assertSame([[10, 0], [4, 0]], [$this->stock($a, 'M'), $this->stock($b, 'S')]);
    }

    public function test_cancelling_copes_with_a_product_that_was_deleted_meanwhile(): void
    {
        [$id, $p] = $this->placed();
        ProductSize::where('product_id', $p->id)->first()->delete();               // order item keeps its snapshot, size link becomes NULL

        $this->me()->postJson("/api/v1/orders/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonCount(1, 'data.items');
    }

    // ------------------------------------------------------------------ return

    private function delivered(?\DateTimeInterface $at = null): int
    {
        [$id] = $this->placed();
        Order::whereKey($id)->update(['status' => 'delivered']);
        OrderStatusHistory::query()->create(['order_id' => $id, 'from_status' => 'shipped', 'to_status' => OrderStatus::Delivered, 'actor_type' => 'admin', 'actor_id' => 1]);
        if ($at) {
            OrderStatusHistory::where('order_id', $id)->where('to_status', 'delivered')->update(['created_at' => $at]);
        }

        return $id;
    }

    public function test_a_delivered_order_can_be_returned_with_a_reason(): void
    {
        $id = $this->delivered();
        $show = $this->me()->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.can_return', true);

        $r = $this->me()->postJson("/api/v1/orders/{$id}/return", ['reason' => 'Size does not fit'])->assertOk()
            ->assertJsonPath('data.status', 'return_requested')->assertJsonPath('data.can_return', false);
        $this->assertSame('Size does not fit', collect($r->json('data.history'))->last()['reason']);
        $this->assertSame('return_requested', collect($r->json('data.history'))->last()['status']);

        $this->me()->postJson("/api/v1/orders/{$id}/return", ['reason' => 'Again please'])->assertStatus(422);        // not twice
    }

    public function test_a_return_needs_a_reason(): void
    {
        $id = $this->delivered();

        foreach ([[], ['reason' => ''], ['reason' => 'no'], ['reason' => str_repeat('x', 256)]] as $bad) {
            $this->me()->postJson("/api/v1/orders/{$id}/return", $bad)->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        }
        $this->assertSame('delivered', Order::find($id)->status->value);
    }

    public function test_only_delivered_orders_can_be_returned(): void
    {
        foreach (['pending', 'confirmed', 'processing', 'packed', 'pickup_requested', 'shipped', 'out_for_delivery', 'cancelled', 'return_requested', 'returned', 'refunded', 'delivery_failed'] as $status) {
            [$id] = $this->placed();
            Order::whereKey($id)->update(['status' => $status]);

            $this->me()->postJson("/api/v1/orders/{$id}/return", ['reason' => 'Not suitable'])->assertStatus(422, $status);
            $this->assertSame($status, Order::find($id)->status->value);
        }
    }

    public function test_the_return_window_is_enforced(): void
    {
        $inside = $this->delivered(now()->subDays(13));
        $outside = $this->delivered(now()->subDays(15));

        $this->me()->postJson("/api/v1/orders/{$inside}/return", ['reason' => 'Changed my mind'])->assertOk();
        $this->me()->postJson("/api/v1/orders/{$outside}/return", ['reason' => 'Changed my mind'])->assertStatus(422)->assertJsonPath('message', 'The 14-day return window for this order has closed.');
        $this->assertSame('delivered', Order::find($outside)->status->value);
    }

    public function test_a_return_request_does_not_change_stock(): void
    {
        $id = $this->delivered();
        $p = Product::latest('id')->first();
        $before = $this->stock($p, 'M');

        $this->me()->postJson("/api/v1/orders/{$id}/return", ['reason' => 'Defective item'])->assertOk();

        $this->assertSame($before, $this->stock($p, 'M'), 'stock only changes when the return is received (admin, Phase 7)');
    }

    // ----------------------------------------------------------- authorization

    public function test_customers_cannot_cancel_or_return_other_peoples_orders(): void
    {
        [$other] = $this->shopper();
        [$id, $p] = $this->placed();
        $deliveredId = $this->delivered();

        $this->actingAsCustomer($other)->postJson("/api/v1/orders/{$id}/cancel")->assertNotFound();
        $this->actingAsCustomer($other)->postJson("/api/v1/orders/{$deliveredId}/return", ['reason' => 'Not mine to return'])->assertNotFound();
        $this->guest()->postJson("/api/v1/orders/{$id}/cancel")->assertUnauthorized();
        $this->actingAsAdmin()->postJson("/api/v1/orders/{$id}/cancel")->assertUnauthorized();

        $this->assertSame('pending', Order::find($id)->status->value);
        $this->assertSame([8, 2], $this->stock($p, 'M'));
    }

    public function test_the_client_cannot_set_an_arbitrary_status(): void
    {
        [$id] = $this->placed();

        $this->me()->patchJson("/api/v1/orders/{$id}", ['status' => 'delivered'])->assertStatus(405);
        $this->me()->putJson("/api/v1/orders/{$id}", ['status' => 'delivered'])->assertStatus(405);
        $this->me()->postJson("/api/v1/orders/{$id}/cancel", ['status' => 'delivered', 'payment_status' => 'paid'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', Order::find($id)->payment_status->value, 'payment status followed the real cancellation, not the injected value');
    }
}
