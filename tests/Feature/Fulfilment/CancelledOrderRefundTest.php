<?php

namespace Tests\Feature\Fulfilment;

use App\Enums\AdminRole;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/** Refund of a PAID order that was cancelled (the order stays cancelled; only the payment becomes refunded). NOT RUN by the author (no PHP runtime). */
class CancelledOrderRefundTest extends TestCase
{
    use FulfilmentTestHelpers, RefreshDatabase;

    public function test_paid_cancelled_order_can_be_refunded_by_super_admin_only(): void
    {
        [$order, $p] = $this->newOrder(2);
        Order::query()->whereKey($order->id)->update(['payment_status' => 'paid']);
        $order->payments()->update(['status' => 'paid']);

        $this->move($order->id, 'cancelled')->assertOk();
        $this->assertSame('refund_pending', Order::find($order->id)->payment_status->value);
        $this->assertSame([10, 0], $this->stock($p, 'M'));          // reservation released back to the shared stock

        $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(403);
        $this->assertSame('refund_pending', Order::find($order->id)->payment_status->value);

        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/refund", ['reference' => 'UTR1'])->assertOk();
        $this->assertSame('cancelled', $this->statusOf($order->id));
        $this->assertSame('refunded', Order::find($order->id)->payment_status->value);

        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(422);   // not refundable twice
    }
}
