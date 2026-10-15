<?php

namespace Tests\Feature\Fulfilment;

use App\Models\ShipmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/** Webhook edge cases. NOTE: written against the existing helper API; NOT executed by the author (no Laravel runtime available). */
class DeliveryWebhookEdgeTest extends TestCase
{
    use FulfilmentTestHelpers, RefreshDatabase;

    public function test_wrong_provider_name_is_404_and_changes_nothing(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;

        $this->webhook([$this->event('e1', $awb, 'picked_up')], null, 'otherprovider')->assertStatus(404);
        $this->assertSame('pickup_requested', $this->statusOf($order->id));
    }

    public function test_missing_empty_or_tampered_signature_is_401(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;
        $body = json_encode(['events' => [$this->event('e1', $awb, 'picked_up')]]);

        $this->webhook([], '', 'mock', $body)->assertStatus(401);
        $this->webhook([], hash_hmac('sha256', $body . ' ', 'test-webhook-secret'), 'mock', $body)->assertStatus(401);
        $this->assertSame('pickup_requested', $this->statusOf($order->id));
        $this->assertSame(0, ShipmentEvent::where('external_id', 'e1')->count());
    }

    public function test_empty_server_secret_rejects_even_a_signature_made_with_an_empty_key(): void
    {
        config(['vistaro.shipping.webhook_secret' => '']);
        $body = json_encode(['events' => []]);

        $this->guest()->call('POST', '/api/v1/webhooks/delivery/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, ''),
        ], $body)->assertStatus(401);
    }

    public function test_malformed_payloads_are_422_not_500(): void
    {
        $this->webhook([], null, 'mock', 'not json')->assertStatus(422);
        $this->webhook([], null, 'mock', json_encode(['nope' => []]))->assertStatus(422);
        $this->webhook([], null, 'mock', json_encode(['events' => [['id' => 'x', 'awb' => 'A', 'status' => 'delivered', 'at' => 'not-a-date']]]))->assertStatus(422);
    }

    public function test_events_missing_required_fields_are_skipped(): void
    {
        $this->webhook([['id' => 'only-id']])->assertOk()->assertJsonPath('data.applied', 0);
    }

    public function test_unknown_awb_is_acknowledged_and_ignored(): void
    {
        $this->webhook([$this->event('e1', 'MOCK-DOESNOTEXIST', 'delivered')])->assertOk()->assertJsonPath('data.unknown', 1);
    }

    public function test_out_of_order_and_stale_events_are_stored_but_do_not_move_the_order_backwards(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;
        $t = now();

        $this->webhook([$this->event('d', $awb, 'delivered', $t->copy()->addHour()->toIso8601String())])->assertOk();
        $this->assertSame('delivered', $this->statusOf($order->id));

        $this->webhook([$this->event('late', $awb, 'in_transit', $t->toIso8601String())])->assertOk();
        $this->assertSame('delivered', $this->statusOf($order->id), 'a stale event never moves the order backwards');
        $this->assertSame(1, ShipmentEvent::where('external_id', 'late')->count(), 'but it is still recorded');
    }

    public function test_a_courier_can_skip_steps_straight_to_delivered(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;

        $this->webhook([$this->event('e1', $awb, 'delivered')])->assertOk();

        $this->assertSame('delivered', $this->statusOf($order->id));
        $this->assertSame('paid', \App\Models\Order::find($order->id)->payment_status->value, 'COD becomes paid on delivery');
    }

    public function test_events_for_a_cancelled_order_change_nothing(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;
        $this->move($order->id, 'cancelled', ['reason' => 'cancelled before pickup'])->assertOk();

        $this->webhook([$this->event('e1', $awb, 'delivered')])->assertOk();

        $this->assertSame('cancelled', $this->statusOf($order->id));
    }

    public function test_failed_delivery_then_retry_then_delivered(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;
        $t = now();

        $this->webhook([$this->event('a', $awb, 'in_transit', $t->toIso8601String())])->assertOk();
        $this->webhook([$this->event('b', $awb, 'failed', $t->copy()->addHour()->toIso8601String())])->assertOk();
        $this->assertSame('delivery_failed', $this->statusOf($order->id));

        $this->webhook([$this->event('c', $awb, 'out_for_delivery', $t->copy()->addHours(2)->toIso8601String())])->assertOk();
        $this->webhook([$this->event('d', $awb, 'delivered', $t->copy()->addHours(3)->toIso8601String())])->assertOk();
        $this->assertSame('delivered', $this->statusOf($order->id));
    }

    public function test_webhook_does_not_require_a_bearer_token(): void
    {
        [$order] = $this->newOrder(1);
        $awb = $this->booked($order->id)->awb;

        $this->webhook([$this->event('e1', $awb, 'picked_up')])->assertOk();
    }
}
