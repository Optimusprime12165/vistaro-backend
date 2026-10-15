<?php

namespace Tests\Feature\Fulfilment;

use App\Enums\AdminRole;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/**
 * Phase 7 completion tests: admin order API (auth, role matrix, list/filters/search/cursor, detail),
 * cancellation at each stage, courier-cancel failure rollback, returns and refunds, pickup/label/tracking edges.
 * NOTE: written against the helper API used by FulfilmentSmokeTest; NOT executed by the author (no Laravel runtime available).
 */
class AdminOrderApiTest extends TestCase
{
    use FulfilmentTestHelpers, RefreshDatabase;

    // ------------------------------------------------------------------ auth

    public function test_order_endpoints_require_an_admin_token(): void
    {
        [$order, , $customer] = $this->newOrder(1);

        $this->guest()->getJson('/api/v1/admin/orders')->assertStatus(401);
        $this->guest()->postJson("/api/v1/admin/orders/{$order->id}/transition", ['status' => 'confirmed'])->assertStatus(401);
        $this->actingAsCustomer($customer)->getJson('/api/v1/admin/orders')->assertStatus(403);
        $this->actingAsCustomer($customer)->postJson("/api/v1/admin/orders/{$order->id}/transition", ['status' => 'confirmed'])->assertStatus(403);
        $this->assertSame('pending', $this->statusOf($order->id));
    }

    // ------------------------------------------------------------ role matrix

    public function test_staff_can_run_the_normal_fulfilment_steps(): void
    {
        [$order] = $this->newOrder(1);

        foreach (['confirmed', 'processing', 'packed'] as $s) {
            $this->move($order->id, $s, [], AdminRole::Staff)->assertOk();
        }
        $this->pickup($order->id, AdminRole::Staff)->assertCreated();
        $this->move($order->id, 'shipped', [], AdminRole::Staff)->assertOk();
        $this->move($order->id, 'out_for_delivery', [], AdminRole::Staff)->assertOk();
        $this->move($order->id, 'delivered', [], AdminRole::Staff)->assertOk();
        $this->assertSame('delivered', $this->statusOf($order->id));
    }

    public function test_staff_cannot_cancel_receive_returns_or_refund_and_nothing_changes(): void
    {
        [$order, $p] = $this->newOrder(2);

        $this->move($order->id, 'cancelled', ['reason' => 'customer asked'], AdminRole::Staff)->assertStatus(403);
        $this->assertSame('pending', $this->statusOf($order->id));
        $this->assertSame([8, 2], $this->stock($p, 'M'), 'a refused cancel must not release stock');

        $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(403);
        $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/customers')->assertStatus(403);
        $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/login-events')->assertStatus(403);
    }

    public function test_staff_may_read_orders_and_the_detail_lists_only_what_staff_can_do(): void
    {
        [$order] = $this->newOrder(1);
        $this->move($order->id, 'confirmed')->assertOk();

        $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/orders')->assertOk();
        $staff = $this->actingAsAdmin(AdminRole::Staff)->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('data.allowed_transitions');
        $admin = $this->actingAsAdmin(AdminRole::Admin)->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('data.allowed_transitions');

        $this->assertSame(['processing'], $staff);
        $this->assertEqualsCanonicalizing(['processing', 'cancelled'], $admin);
    }

    public function test_super_admin_passes_every_role_gate(): void
    {
        [$order] = $this->newOrder(1);
        $this->move($order->id, 'cancelled', ['reason' => 'test cancel'], AdminRole::SuperAdmin)->assertOk();
        $this->assertSame('cancelled', $this->statusOf($order->id));
        $this->actingAsAdmin(AdminRole::SuperAdmin)->getJson('/api/v1/admin/customers')->assertOk();
    }

    // ---------------------------------------------------- transition validation

    public function test_transition_validation_errors(): void
    {
        [$order] = $this->newOrder(1);
        $url = "/api/v1/admin/orders/{$order->id}/transition";

        $this->actingAsAdmin()->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAsAdmin()->postJson($url, ['status' => 'teleported'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAsAdmin()->postJson($url, ['status' => 'pickup_requested'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAsAdmin()->postJson($url, ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAsAdmin()->postJson($url, ['status' => 'cancelled', 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('pending', $this->statusOf($order->id));
    }

    public function test_unknown_order_is_404_and_non_numeric_id_does_not_match_a_route(): void
    {
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders/999999')->assertStatus(404);
        $this->actingAsAdmin()->postJson('/api/v1/admin/orders/999999/transition', ['status' => 'confirmed'])->assertStatus(404);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders/abc')->assertStatus(404);
    }

    public function test_every_transition_writes_history_with_the_actor_and_reason(): void
    {
        [$order] = $this->newOrder(1);
        $this->move($order->id, 'confirmed', ['reason' => 'verified by phone'])->assertOk();

        $h = OrderStatusHistory::where('order_id', $order->id)->latest('id')->first();
        $this->assertSame('confirmed', $h->to_status->value);
        $this->assertSame('pending', $h->from_status->value);
        $this->assertSame('admin', $h->actor_type);
        $this->assertNotNull($h->actor_id);
        $this->assertSame('verified by phone', $h->reason);
    }

    // ------------------------------------------------------------------ listing

    public function test_list_filters_by_status_method_and_rejects_bad_filters(): void
    {
        [$a] = $this->newOrder(1);
        [$b] = $this->newOrder(1);
        $this->move($b->id, 'confirmed')->assertOk();

        $ids = fn (string $qs) => collect($this->actingAsAdmin()->getJson("/api/v1/admin/orders?{$qs}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$b->id], $ids('status=confirmed'));
        $this->assertSame([$a->id, $b->id], collect($ids('payment_method=cod'))->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids('per_page=20'));

        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?status=nonsense')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?sort=password')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?per_page=101')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?date_from=10-10-2026')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?date_from=2026-10-10&date_to=2026-10-01')->assertStatus(422);
    }

    public function test_search_by_order_number_prefix_and_no_match_returns_empty(): void
    {
        [$order] = $this->newOrder(1);

        $hit = $this->actingAsAdmin()->getJson('/api/v1/admin/orders?q=' . urlencode(substr($order->order_number, 0, 6)))->assertOk()->json('data');
        $this->assertContains($order->id, collect($hit)->pluck('id')->all());

        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?q=ZZZZ-NO-SUCH')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cursor_pagination_has_no_overlap_and_no_gaps(): void
    {
        $made = [];
        for ($i = 0; $i < 3; $i++) {
            $made[] = $this->newOrder(1)[0]->id;
        }

        $seen = [];
        $res = $this->actingAsAdmin()->getJson('/api/v1/admin/orders?per_page=2')->assertOk();
        $seen = array_merge($seen, collect($res->json('data'))->pluck('id')->all());
        $this->assertTrue($res->json('meta.has_more'));
        $cursor = $res->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $res2 = $this->actingAsAdmin()->getJson('/api/v1/admin/orders?per_page=2&cursor=' . urlencode($cursor))->assertOk();
        $seen = array_merge($seen, collect($res2->json('data'))->pluck('id')->all());

        $this->assertCount(3, array_unique($seen), 'no repeated rows across pages');
        $this->assertEqualsCanonicalizing($made, $seen);
        $this->assertFalse($res2->json('meta.has_more'));
    }

    // -------------------------------------------------------------- cancellation

    public function test_admin_cancel_releases_stock_and_cancels_payment_at_every_stage_before_shipping(): void
    {
        foreach (['pending', 'confirmed', 'processing', 'packed'] as $stage) {
            [$order, $p] = $this->newOrder(2);
            match ($stage) {
                'confirmed' => $this->move($order->id, 'confirmed')->assertOk(),
                'processing' => array_map(fn ($s) => $this->move($order->id, $s)->assertOk(), ['confirmed', 'processing']),
                'packed' => $this->pack($order->id),
                default => null,
            };

            $this->move($order->id, 'cancelled', ['reason' => "cancel at {$stage}"])->assertOk();

            $this->assertSame('cancelled', $this->statusOf($order->id), $stage);
            $this->assertSame([10, 0], $this->stock($p, 'M'), "stock fully released when cancelled at {$stage}");
            $this->assertSame('cancelled', Order::find($order->id)->payment_status->value, $stage);
        }
    }

    public function test_cancel_after_pickup_is_booked_cancels_the_shipment_at_the_courier(): void
    {
        $this->useSpyCourier();
        [$order, $p] = $this->newOrder(2);
        $shipment = $this->booked($order->id);

        $this->move($order->id, 'cancelled', ['reason' => 'changed mind'])->assertOk();

        $this->assertSame(1, $this->courier->count('cancel'));
        $this->assertSame('cancelled', $shipment->refresh()->status->value);
        $this->assertSame([10, 0], $this->stock($p, 'M'));
    }

    public function test_courier_refusing_the_cancel_rolls_everything_back(): void
    {
        $this->useSpyCourier();
        [$order, $p] = $this->newOrder(2);
        $shipment = $this->booked($order->id);
        $this->courier->cancelSucceeds = false;

        $this->move($order->id, 'cancelled', ['reason' => 'changed mind'])->assertStatus(409);

        $this->assertSame('pickup_requested', $this->statusOf($order->id));
        $this->assertSame('pickup_requested', $shipment->refresh()->status->value);
        $this->assertSame([8, 2], $this->stock($p, 'M'), 'stock stays reserved');
        $this->assertSame('pending', Order::find($order->id)->payment_status->value);
    }

    public function test_cancel_is_refused_once_shipped_delivered_or_already_cancelled(): void
    {
        [$a] = $this->newOrder(1);
        $this->shipped($a->id);
        $this->move($a->id, 'cancelled', ['reason' => 'too late'])->assertStatus(422);

        [$b] = $this->newOrder(1);
        $this->move($b->id, 'cancelled', ['reason' => 'first cancel'])->assertOk();
        $this->move($b->id, 'cancelled', ['reason' => 'second cancel'])->assertStatus(422);
        $this->move($b->id, 'confirmed')->assertStatus(422);
    }

    public function test_customer_cancel_and_admin_cancel_cannot_both_win(): void
    {
        [$order, $p, $customer] = $this->newOrder(2);

        $this->actingAsCustomer($customer)->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->move($order->id, 'cancelled', ['reason' => 'admin also cancels'])->assertStatus(422);

        $this->assertSame([10, 0], $this->stock($p, 'M'), 'stock released exactly once');
    }

    // ---------------------------------------------------------- returns/refunds

    public function test_return_with_restock_puts_units_back_on_sale_then_refund_is_recorded(): void
    {
        [$order, $p, $customer] = $this->newOrder(2);
        $this->delivered($order->id);
        $this->assertSame([8, 0], $this->stock($p, 'M'));

        $this->actingAsCustomer($customer)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'wrong size'])->assertOk();
        $this->assertSame('return_requested', $this->statusOf($order->id));

        $this->move($order->id, 'returned')->assertStatus(422)->assertJsonValidationErrors('restock');
        $this->move($order->id, 'returned', ['restock' => true])->assertOk();
        $this->assertSame([10, 0], $this->stock($p, 'M'));

        $this->move($order->id, 'refund_pending')->assertOk();
        $this->assertSame('refund_pending', Order::find($order->id)->payment_status->value);

        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/refund", ['reference' => 'UTR123', 'note' => 'sent by bank transfer'])->assertOk();
        $this->assertSame('refunded', $this->statusOf($order->id));
        $this->assertSame('refunded', Order::find($order->id)->payment_status->value);
    }

    public function test_return_without_restock_leaves_stock_unchanged(): void
    {
        [$order, $p, $customer] = $this->newOrder(2);
        $this->delivered($order->id);
        $this->actingAsCustomer($customer)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'damaged item'])->assertOk();

        $this->move($order->id, 'returned', ['restock' => false])->assertOk();

        $this->assertSame([8, 0], $this->stock($p, 'M'));
    }

    public function test_refund_endpoint_is_refused_unless_the_order_is_refund_pending(): void
    {
        [$order] = $this->newOrder(1);

        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(422);
        $this->assertSame('pending', $this->statusOf($order->id));
    }

    // ----------------------------------------------------- pickup/label/tracking

    public function test_pickup_requires_a_packed_order_and_cannot_be_booked_twice(): void
    {
        [$order] = $this->newOrder(1);

        $this->pickup($order->id)->assertStatus(422);
        $this->pack($order->id);
        $this->pickup($order->id)->assertCreated();
        $this->pickup($order->id)->assertStatus(422);
        $this->assertSame(1, \App\Models\Shipment::where('order_id', $order->id)->count());
    }

    public function test_pickup_in_the_past_is_a_validation_error(): void
    {
        [$order] = $this->newOrder(1);
        $this->pack($order->id);

        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/pickup", ['pickup_at' => now()->subDay()->toDateTimeString()])
            ->assertStatus(422)->assertJsonValidationErrors('pickup_at');
        $this->assertSame('packed', $this->statusOf($order->id));
    }

    public function test_pickup_sends_cod_amount_to_the_courier_for_cod_orders_only(): void
    {
        $this->useSpyCourier();
        [$cod] = $this->newOrder(1, 'cod');
        $this->booked($cod->id);
        $this->assertTrue($this->courier->lastRequest->cod);
        $this->assertSame((string) Order::find($cod->id)->total, $this->courier->lastRequest->codAmount);
    }

    public function test_label_needs_an_active_shipment_and_is_served_safely(): void
    {
        [$order] = $this->newOrder(1);
        $this->actingAsAdmin()->get("/api/v1/admin/orders/{$order->id}/label")->assertStatus(409);

        $this->booked($order->id);
        $this->actingAsAdmin()->get("/api/v1/admin/orders/{$order->id}/label")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Mock-Shipment', 'true');
    }

    public function test_tracking_is_empty_without_a_shipment_and_sync_is_idempotent(): void
    {
        [$order] = $this->newOrder(1);
        $this->actingAsAdmin()->getJson("/api/v1/admin/orders/{$order->id}/tracking")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/tracking/sync")->assertStatus(409);

        $this->booked($order->id);
        $first = $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/tracking/sync")->assertOk()->json('data');
        $second = $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/tracking/sync")->assertOk()->json('data');

        $this->assertSame(1, $first['applied']);
        $this->assertSame([0, 1], [$second['applied'], $second['duplicates']]);
    }

    // ------------------------------------------------------ customers/login events

    public function test_customers_and_login_events_lists_work_for_admin(): void
    {
        [$order, , $customer] = $this->newOrder(1);

        $list = $this->actingAsAdmin()->getJson('/api/v1/admin/customers')->assertOk()->json('data');
        $this->assertContains($customer->id, collect($list)->pluck('id')->all());
        $this->actingAsAdmin()->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();
        $this->actingAsAdmin()->getJson('/api/v1/admin/customers/999999')->assertStatus(404);
        $this->actingAsAdmin()->getJson('/api/v1/admin/login-events')->assertOk();
    }
}
