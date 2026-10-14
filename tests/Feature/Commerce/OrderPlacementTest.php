<?php

namespace Tests\Feature\Commerce;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\Payment\PaymentResult;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IdempotencyKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class OrderPlacementTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private User $user;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->user, $this->address] = $this->shopper();
    }

    private function order(string $method = 'cod', ?string $key = null, array $extra = [])
    {
        return $this->placeOrder($this->user, $this->address, $method, $key, $extra);
    }

    private function counts(): array
    {
        return [Order::count(), OrderItem::count(), Payment::count(), InventoryMovement::count(), CartItem::count()];
    }

    // -------------------------------------------------------------- request rules

    public function test_the_idempotency_key_header_is_mandatory_and_validated(): void
    {
        $this->addToCart($this->user, $this->makeProduct(['M' => 5]), 'M');
        $body = ['address_id' => $this->address->id, 'payment_method' => 'cod'];

        $this->actingAsCustomer($this->user)->postJson('/api/v1/orders', $body)->assertStatus(422)->assertJsonPath('errors.idempotency_key.0', 'The Idempotency-Key header is required.');
        foreach (['short', 'has spaces in it', "bad/slash/key", str_repeat('a', 101), 'emoji-🙂-key-12'] as $bad) {
            $this->actingAsCustomer($this->user)->postJson('/api/v1/orders', $body, ['Idempotency-Key' => $bad])->assertStatus(422);
        }
        $this->assertSame(0, Order::count());
    }

    public function test_address_payment_and_cart_are_validated(): void
    {
        [, $theirs] = $this->shopper();

        $this->order('cod')->assertStatus(422)->assertJsonStructure(['errors' => ['cart']]);               // empty cart
        $this->addToCart($this->user, $this->makeProduct(['M' => 5]), 'M');

        $this->actingAsCustomer($this->user)->postJson('/api/v1/orders', ['payment_method' => 'cod'], ['Idempotency-Key' => 'key-0000001'])->assertStatus(422)->assertJsonStructure(['errors' => ['address_id']]);
        $this->actingAsCustomer($this->user)->postJson('/api/v1/orders', ['address_id' => $theirs->id, 'payment_method' => 'cod'], ['Idempotency-Key' => 'key-0000002'])->assertStatus(422)->assertJsonStructure(['errors' => ['address_id']]);
        $this->actingAsCustomer($this->user)->postJson('/api/v1/orders', ['address_id' => $this->address->id], ['Idempotency-Key' => 'key-0000003'])->assertStatus(422)->assertJsonStructure(['errors' => ['payment_method']]);
        $this->order('crypto')->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_orders_require_a_customer_token(): void
    {
        $this->guest()->postJson('/api/v1/orders', [], ['Idempotency-Key' => 'key-0000001'])->assertUnauthorized();
        $this->actingAsAdmin()->postJson('/api/v1/orders', [], ['Idempotency-Key' => 'key-0000001'])->assertUnauthorized();
        $this->guest()->getJson('/api/v1/orders')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- success

    public function test_a_cod_order_is_created_with_snapshots_reservation_history_and_a_cleared_cart(): void
    {
        $p = $this->makeProduct(['M' => 10, 'L' => 5], ['name' => 'Linen Shirt', 'price' => '1200.00', 'mrp' => '1600.00', 'sku' => 'VS-LIN-1'], [['Navy', '#1F2A44']]);
        $q = $this->makeProduct(['S' => 3], ['name' => 'Chino', 'price' => '999.50', 'mrp' => '1500.00']);
        $navy = $p->colors->first();
        $this->addToCart($this->user, $p, 'M', 2, $navy->id);
        $this->addToCart($this->user, $q, 'S', 1);

        $r = $this->order('cod')->assertCreated()->assertJsonPath('success', true);

        // 2 x 1200 + 999.50 = 3399.50 (free shipping)
        $d = $r->json('data');
        $this->assertSame(['VS' . (10000 + $d['id']), 'pending', 'pending', 'cod'], [$d['order_number'], $d['status'], $d['payment_status'], $d['payment_method']]);
        $this->assertSame(['3399.50', '4700.00', '1300.50', '0.00', '3399.50', 'INR'], [$d['subtotal'], $d['mrp_total'], $d['discount_total'], $d['shipping_fee'], $d['total'], $d['currency']]);
        $this->assertStringContainsString('cash', $r->json('message'));
        $this->assertSame(['cod', 'pending', false], [$d['payment']['method'], $d['payment']['status'], $d['payment']['is_mock']]);
        $this->assertFalse($d['can_return']);
        $this->assertTrue($d['can_cancel']);

        $item = collect($d['items'])->firstWhere('name', 'Linen Shirt');
        $this->assertSame(['M', 2, '1200.00', '1600.00', '2400.00', 'Navy', 'VS-LIN-1'], [$item['size'], $item['quantity'], $item['unit_price'], $item['mrp'], $item['line_total'], $item['color']['name'], $item['sku']]);
        $this->assertSame('Pending', ucfirst($d['history'][0]['status']));
        $this->assertSame([null], collect($d['history'])->pluck('from')->all());
        $this->assertSame($this->address->pincode, $d['address']['pincode']);

        $order = Order::firstOrFail();
        $this->assertSame($this->user->id, $order->user_id);
        $this->assertSame([2, 1, 1, 1], [$order->items()->count() , OrderAddress::count(), Payment::count(), OrderStatusHistory::count()]);
        $this->assertSame(0, CartItem::count(), 'cart emptied only after success');

        // stock: available -> reserved, one movement per stock row
        $this->assertSame([8, 2], $this->stock($p, 'M'));
        $this->assertSame([5, 0], $this->stock($p, 'L'));
        $this->assertSame([2, 1], $this->stock($q, 'S'));
        $moves = InventoryMovement::where('type', 'order_reserved')->orderBy('id')->get();
        $this->assertSame([-2, -1], $moves->pluck('quantity_change')->all());
        $this->assertSame([2, 1], $moves->pluck('reserved_change')->all());
        $this->assertSame(['order', 'order'], $moves->pluck('reference_type')->all());
        $this->assertSame([$order->id, $order->id], $moves->pluck('reference_id')->all());
        $this->assertSame(['user', 'user'], $moves->pluck('actor_type')->all());
    }

    public function test_snapshots_do_not_change_when_the_catalogue_or_address_changes_later(): void
    {
        $p = $this->makeProduct(['M' => 10], ['name' => 'Original Name', 'price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($this->user, $p, 'M', 1);
        $id = $this->order()->assertCreated()->json('data.id');

        $p->update(['name' => 'Renamed', 'price' => 1, 'mrp' => 2]);
        $this->address->update(['city' => 'Changed City', 'line1' => 'Changed line']);

        $r = $this->actingAsCustomer($this->user)->getJson("/api/v1/orders/{$id}")->assertOk();
        $this->assertSame(['Original Name', '1000.00', '1099.00'], [$r->json('data.items.0.name'), $r->json('data.items.0.unit_price'), $r->json('data.total')]);
        $this->assertNotSame('Changed City', $r->json('data.address.city'));

        $this->expectException(\LogicException::class);
        OrderAddress::firstOrFail()->update(['city' => 'tamper']);
    }

    public function test_online_payment_orders_stay_payment_pending_and_say_it_is_a_mock(): void
    {
        foreach (['upi', 'card'] as $method) {
            $this->addToCart($this->user, $this->makeProduct(['M' => 5]), 'M');
            $r = $this->order($method)->assertCreated();

            $this->assertSame(['pending', 'pending'], [$r->json('data.payment_status'), $r->json('data.payment.status')], 'never paid without a provider confirmation');
            $this->assertTrue($r->json('data.payment.is_mock'));
            $this->assertStringContainsString('no money was collected', $r->json('message'));
            $pay = Payment::latest('id')->first();
            $this->assertSame(['mock', true, 'pending', null], [$pay->gateway, $pay->is_mock, $pay->status->value, $pay->paid_at]);
            $this->assertStringStartsWith('MOCKPAY-', $pay->reference);
        }
        $this->assertSame(0, Payment::where('status', 'paid')->count());
        $this->assertSame(0, Order::where('payment_status', 'paid')->count());
    }

    public function test_a_gateway_that_claims_paid_is_still_recorded_as_pending(): void
    {
        $this->app->instance(PaymentGatewayInterface::class, new class implements PaymentGatewayInterface {
            public function name(): string { return 'fake'; }
            public function isMock(): bool { return false; }
            public function supports(string $m): bool { return true; }
            public function initiate(string $o, string $a, string $m): PaymentResult { return new PaymentResult(PaymentResult::PAID, 'fake', false, 'REF1'); }
        });
        $this->addToCart($this->user, $this->makeProduct(['M' => 5]), 'M');

        $r = $this->order('upi')->assertCreated();

        $this->assertSame('pending', $r->json('data.payment_status'), 'initiation is not confirmation');
        $this->assertSame('pending', Payment::first()->status->value);
        $this->assertStringNotContainsString('mock', strtolower($r->json('message')));
    }

    public function test_the_client_cannot_inject_prices_totals_status_or_items(): void
    {
        $p = $this->makeProduct(['M' => 5], ['price' => '1000.00', 'mrp' => '1500.00']);
        $this->addToCart($this->user, $p, 'M', 1);

        $r = $this->order('cod', null, [
            'total' => 1, 'subtotal' => 1, 'shipping_fee' => 0, 'discount_total' => 9999, 'status' => 'delivered', 'payment_status' => 'paid',
            'items' => [['product_id' => 999, 'price' => 1, 'quantity' => 50]], 'user_id' => 9999, 'order_number' => 'HACK1',
        ])->assertCreated();

        $this->assertSame(['pending', 'pending', '1099.00', $this->user->id], [$r->json('data.status'), $r->json('data.payment_status'), $r->json('data.total'), Order::first()->user_id]);
        $this->assertStringStartsWith('VS', $r->json('data.order_number'));
        $this->assertSame(1, OrderItem::sole()->quantity);
    }

    public function test_order_numbers_are_unique_and_follow_the_convention(): void
    {
        $numbers = [];
        foreach (range(1, 4) as $i) {
            $this->addToCart($this->user, $this->makeProduct(['M' => 5]), 'M');
            $numbers[] = $this->order()->assertCreated()->json('data.order_number');
        }

        $this->assertCount(4, array_unique($numbers));
        foreach ($numbers as $n) {
            $this->assertMatchesRegularExpression('/^VS\d{5,}$/', $n);
        }
    }

    // ----------------------------------------------------------- idempotency

    public function test_retrying_the_same_request_returns_the_same_order_and_creates_nothing_new(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($this->user, $p, 'M', 2);

        $first = $this->order('cod', 'order-key-0001')->assertCreated();
        $before = $this->counts();

        $this->addToCart($this->user, $p, 'M', 1);                                    // a NEW cart item appears after the first order
        $second = $this->order('cod', 'order-key-0001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data'), $second->json('data'), 'the stored response is replayed verbatim');
        $this->assertSame($first->json('message'), $second->json('message'));
        $this->assertSame(1, Order::count());
        $this->assertSame([8, 2], $this->stock($p, 'M'), 'stock was reserved exactly once');
        $this->assertSame(1, CartItem::count(), 'the replay did not consume the new cart item');
        $this->assertSame(array_slice($before, 0, 4), array_slice($this->counts(), 0, 4));
        $this->assertNull($first->headers->get('Idempotent-Replayed'));
        $this->assertSame('completed', IdempotencyKey::sole()->state);
        $this->assertSame(Order::first()->id, IdempotencyKey::sole()->order_id);
    }

    public function test_reusing_a_key_with_a_different_payload_is_refused(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($this->user, $p, 'M');
        $this->order('cod', 'order-key-0002')->assertCreated();

        $this->addToCart($this->user, $p, 'M');
        $this->order('upi', 'order-key-0002')->assertStatus(422)->assertJsonStructure(['errors' => ['idempotency_key']]);
        $other = Address::factory()->for($this->user)->create();
        $this->placeOrder($this->user, $other, 'cod', 'order-key-0002')->assertStatus(422);

        $this->assertSame(1, Order::count());
        $this->assertSame([9, 1], $this->stock($p, 'M'));
        $this->assertSame(1, CartItem::count(), 'the refused request did not touch the cart');
    }

    public function test_different_keys_create_different_orders_and_keys_are_per_customer(): void
    {
        [$u2, $a2] = $this->shopper();
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($this->user, $p, 'M');
        $this->addToCart($u2, $p, 'M');

        $a = $this->order('cod', 'shared-key-123')->assertCreated()->json('data.id');
        $b = $this->placeOrder($u2, $a2, 'cod', 'shared-key-123')->assertCreated()->json('data.id');   // same key, different customer: independent

        $this->assertNotSame($a, $b);
        $this->assertSame(2, Order::count());
        $this->assertSame(2, IdempotencyKey::count());
    }

    public function test_a_failed_attempt_leaves_the_key_usable_for_a_corrected_retry(): void
    {
        $this->order('cod', 'order-key-0003')->assertStatus(422);                       // empty cart
        $this->assertSame('processing', IdempotencyKey::sole()->state);

        $this->addToCart($this->user, $this->makeProduct(['M' => 5]), 'M');
        $this->order('cod', 'order-key-0003')->assertCreated();                         // same key now works
        $this->assertSame(1, Order::count());
        $this->assertSame('completed', IdempotencyKey::sole()->state);
    }

    // ----------------------------------------------------------- rollback

    public function test_any_failure_rolls_back_the_whole_order(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($this->user, $p, 'M', 2);
        $this->app->instance(PaymentGatewayInterface::class, new class implements PaymentGatewayInterface {
            public function name(): string { return 'broken'; }
            public function isMock(): bool { return true; }
            public function supports(string $m): bool { return true; }
            public function initiate(string $o, string $a, string $m): PaymentResult { throw new \RuntimeException('gateway down'); }
        });

        $this->order('upi', 'order-key-0004')->assertStatus(500)->assertJsonPath('success', false);

        $this->assertSame([0, 0, 0, 0], array_slice($this->counts(), 0, 4), 'no order, items, payment or stock movement survives');
        $this->assertSame(0, OrderAddress::count() + OrderStatusHistory::count());
        $this->assertSame([10, 0], $this->stock($p, 'M'), 'the reservation was rolled back');
        $this->assertSame(1, CartItem::count(), 'the cart is untouched');
        $this->assertSame('processing', IdempotencyKey::sole()->state);

        $this->order('cod', 'order-key-0004')->assertCreated();                         // COD does not use the gateway: same key, new payload
        $this->assertSame([8, 2], $this->stock($p, 'M'));
    }

    public function test_a_late_stock_failure_rolls_back_earlier_reservations(): void
    {
        $a = $this->makeProduct(['M' => 10]);
        $b = $this->makeProduct(['M' => 5]);
        $this->addToCart($this->user, $a, 'M', 3);
        $this->addToCart($this->user, $b, 'M', 4);
        ProductSize::where('product_id', $b->id)->update(['quantity' => 2]);               // someone else bought it meanwhile

        $itemB = CartItem::whereHas('productSize', fn ($q) => $q->where('product_id', $b->id))->value('id');
        $r = $this->order('cod', 'order-key-0005')->assertStatus(409)->assertJsonPath('success', false);

        $this->assertArrayHasKey("items.{$itemB}", $r->json('errors'));
        $this->assertSame('Only 2 left in size M.', $r->json('errors')["items.{$itemB}"][0]);
        $this->assertSame([10, 0], $this->stock($a, 'M'), 'the first product was not reserved');
        $this->assertSame([2, 0], $this->stock($b, 'M'));
        $this->assertSame([0, 0, 0, 0], array_slice($this->counts(), 0, 4));
        $this->assertSame(2, CartItem::count());
    }

    // ------------------------------------------------------- stock & availability

    public function test_insufficient_stock_and_unavailable_products_are_rejected(): void
    {
        $short = $this->makeProduct(['M' => 3]);
        $this->addToCart($this->user, $short, 'M', 3);
        ProductSize::where('product_id', $short->id)->update(['quantity' => 2]);
        $this->order()->assertStatus(409);

        $short->update(['status' => 'draft']);
        $this->order()->assertStatus(422)->assertJsonPath('message', 'Some items in your cart are no longer available.');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_colour_lines_of_one_size_are_reserved_together_and_cannot_exceed_stock(): void
    {
        $p = $this->makeProduct(['M' => 5], [], [['Black', '#111111'], ['Navy', '#1F2A44']]);
        [$black, $navy] = $p->colors->all();
        $this->addToCart($this->user, $p, 'M', 3, $black->id);
        $this->addToCart($this->user, $p, 'M', 2, $navy->id);

        $this->order()->assertCreated();
        $this->assertSame([0, 5], $this->stock($p, 'M'));
        $this->assertSame(1, InventoryMovement::where('type', 'order_reserved')->count(), 'one reservation per stock row');
        $this->assertSame(2, OrderItem::count());
    }

    public function test_the_last_unit_goes_to_exactly_one_customer(): void
    {
        [$u2, $a2] = $this->shopper();
        $p = $this->makeProduct(['M' => 1]);
        $this->addToCart($this->user, $p, 'M');
        $this->addToCart($u2, $p, 'M');

        $this->order()->assertCreated();
        $this->placeOrder($u2, $a2)->assertStatus(409)->assertJsonPath('success', false);

        $this->assertSame([0, 1], $this->stock($p, 'M'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, CartItem::count(), 'the loser keeps their cart');
    }

    // ------------------------------------------------------ reading orders

    public function test_customers_only_see_their_own_orders(): void
    {
        [$u2, $a2] = $this->shopper();
        $p = $this->makeProduct(['M' => 20]);
        $this->addToCart($this->user, $p, 'M');
        $mine = $this->order()->json('data.id');
        $this->addToCart($u2, $p, 'M');
        $theirs = $this->placeOrder($u2, $a2)->json('data.id');

        $this->actingAsCustomer($this->user)->getJson("/api/v1/orders/{$theirs}")->assertNotFound();
        $this->actingAsCustomer($this->user)->getJson("/api/v1/orders/{$mine}")->assertOk();
        $ids = collect($this->actingAsCustomer($this->user)->getJson('/api/v1/orders')->json('data'))->pluck('id')->all();
        $this->assertSame([$mine], $ids);
        $this->actingAsCustomer($this->user)->getJson('/api/v1/orders/999999')->assertNotFound();
    }

    public function test_order_list_pagination_filter_and_detail(): void
    {
        $ids = [];
        foreach (range(1, 25) as $i) {
            $this->addToCart($this->user, $this->makeProduct(['M' => 3]), 'M');
            $ids[] = $this->order()->json('data.id');
        }
        Order::whereKey($ids[0])->update(['status' => 'shipped']);

        $page = $this->actingAsCustomer($this->user)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.has_more', true);
        $this->assertSame(array_reverse(array_slice($ids, -10)), collect($page->json('data'))->pluck('id')->all(), 'newest first');
        $this->assertArrayNotHasKey('address', $page->json('data.0'), 'lists are lightweight');
        $this->assertArrayHasKey('items', $page->json('data.0'));

        $this->actingAsCustomer($this->user)->getJson('/api/v1/orders?per_page=20&page=2')->assertJsonCount(5, 'data')->assertJsonPath('meta.has_more', false);
        $this->actingAsCustomer($this->user)->getJson('/api/v1/orders?per_page=101')->assertStatus(422);
        $this->actingAsCustomer($this->user)->getJson('/api/v1/orders?status=shipped')->assertJsonCount(1, 'data');
        $this->actingAsCustomer($this->user)->getJson('/api/v1/orders?status=bogus')->assertStatus(422);

        $detail = $this->actingAsCustomer($this->user)->getJson("/api/v1/orders/{$ids[0]}")->assertOk();
        $this->assertArrayHasKey('address', $detail->json('data'));
        $this->assertArrayHasKey('history', $detail->json('data'));
    }
}
