<?php

namespace Tests\Feature\Fulfilment;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/** Quick end-to-end smoke test of the Phase 7 happy path. (The full Phase 7 test suite is NOT written yet.) */
class FulfilmentSmokeTest extends TestCase
{
    use FulfilmentTestHelpers, RefreshDatabase;

    public function test_cod_order_goes_from_pending_to_delivered_with_stock_and_payment_effects(): void
    {
        [$order, $p, $customer] = $this->newOrder(2);
        $this->assertSame([8, 2], $this->stock($p, 'M'));

        $this->invalidJump($order->id);
        $shipment = $this->delivered($order->id);

        $this->assertSame('delivered', $this->statusOf($order->id));
        $this->assertStringStartsWith('MOCK-', $shipment->awb);
        $this->assertTrue($shipment->is_mock);
        $this->assertSame([8, 0], $this->stock($p, 'M'), 'reservation consumed on shipping, sellable stock unchanged');
        $this->assertSame(1, InventoryMovement::where('type', 'order_fulfilled')->count());
        $this->assertSame('paid', Order::find($order->id)->payment_status->value, 'COD is paid at delivery');

        $this->actingAsAdmin()->getJson('/api/v1/admin/orders')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->actingAsAdmin()->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.shipment.is_mock', true);
        $this->actingAsAdmin()->get("/api/v1/admin/orders/{$order->id}/label")->assertOk()->assertSee('NOT A REAL COURIER BOOKING');
        $this->actingAsCustomer($customer)->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.shipment.awb', $shipment->awb);
    }

    private function invalidJump(int $id): void
    {
        $this->move($id, 'shipped')->assertStatus(422);
        $this->move($id, 'pickup_requested')->assertStatus(422);
        $this->assertSame('pending', $this->statusOf($id));
    }

    public function test_courier_webhook_moves_the_order_forward_and_duplicates_are_ignored(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;

        $this->webhook([$this->event('e1', $awb, 'picked_up')])->assertOk()->assertJsonPath('data.applied', 1);
        $this->assertSame('shipped', $this->statusOf($order->id));
        $this->webhook([$this->event('e1', $awb, 'picked_up')])->assertOk()->assertJsonPath('data.duplicates', 1);
        $this->webhook([$this->event('e2', $awb, 'delivered')])->assertOk();
        $this->assertSame('delivered', $this->statusOf($order->id));
        $this->webhook([$this->event('e3', $awb, 'delivered')], 'bad-signature')->assertStatus(401);
    }
}
