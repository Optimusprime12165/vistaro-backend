<?php

namespace Tests\Feature\Checkout;

use App\Models\Address;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/** Checkout address behaviour: default / selected address, ownership, immutable snapshots, edits, deletion, idempotent retries. */
class CheckoutAddressTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    /** @return array{0:User,1:Address,2:Address} customer with a DEFAULT address and a second, non-default one */
    private function customerWithTwoAddresses(): array
    {
        [$user, $default] = $this->shopper();
        $second = Address::factory()->create(['user_id' => $user->id, 'is_default' => false, 'line1' => '22 Second Street', 'city' => 'Udaipur']);

        return [$user, $default, $second];
    }

    private function cartWith(User $user, int $qty = 2): \App\Models\Product
    {
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($user, $p, 'M', $qty)->assertSuccessful();

        return $p;
    }

    // ------------------------------------------------------------ default / selected

    public function test_order_uses_the_default_address_when_none_is_selected(): void
    {
        [$user, $default] = $this->customerWithTwoAddresses();
        $this->cartWith($user);

        $res = $this->placeOrder($user, $default, 'cod', null, ['payment_method' => 'cod'])->assertCreated();

        $order = Order::findOrFail($res->json('data.id'));
        $this->assertSame($default->line1, $order->address->line1);
        $this->assertSame($default->name, $order->contact_name);
        $this->assertSame($default->line1, $res->json('data.address.line1'));
    }

    public function test_an_explicitly_selected_address_beats_the_default(): void
    {
        [$user, $default, $second] = $this->customerWithTwoAddresses();
        $this->cartWith($user);

        $res = $this->placeOrder($user, $second)->assertCreated();

        $this->assertSame('22 Second Street', $res->json('data.address.line1'));
        $this->assertSame('Udaipur', $res->json('data.address.city'));
        $this->assertNotSame($default->line1, $res->json('data.address.line1'));
    }

    public function test_preview_also_falls_back_to_the_default_address(): void
    {
        [$user, $default, $second] = $this->customerWithTwoAddresses();
        $this->cartWith($user);

        $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview')->assertOk()
            ->assertJsonPath('data.address.line1', $default->line1);
        $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview?address_id=' . $second->id)->assertOk()
            ->assertJsonPath('data.address.line1', '22 Second Street');
    }

    public function test_customer_without_any_address_gets_a_clear_422_and_nothing_is_created(): void
    {
        $user = User::factory()->create();
        $p = $this->cartWith($user);

        $this->placeOrder($user, new Address(), 'cod', null, ['payment_method' => 'cod'])->assertStatus(422)->assertJsonPath('success', false);
        $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview')->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame([10, 0], $this->stock($p, 'M'));
    }

    // ------------------------------------------------------------------ ownership

    public function test_someone_elses_address_is_rejected_and_leaves_cart_and_stock_untouched(): void
    {
        [$user, $mine] = $this->shopper();
        [, $theirs] = $this->shopper();
        $p = $this->cartWith($user);

        $this->placeOrder($user, $theirs)->assertStatus(422);
        $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview?address_id=' . $theirs->id)->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame([10, 0], $this->stock($p, 'M'));
        $this->actingAsCustomer($user)->getJson('/api/v1/cart')->assertOk();   // cart still there
        $this->placeOrder($user, $mine)->assertCreated();                       // and the owner's own address still works
    }

    public function test_nonexistent_address_id_is_rejected(): void
    {
        [$user, $a] = $this->shopper();
        $this->cartWith($user);

        $this->placeOrder($user, $a, 'cod', null, ['address_id' => 999999, 'payment_method' => 'cod'])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_customer_cannot_read_edit_or_delete_another_customers_address(): void
    {
        [$user] = $this->shopper();
        [, $theirs] = $this->shopper();

        $this->actingAsCustomer($user)->getJson("/api/v1/addresses/{$theirs->id}")->assertNotFound();
        $this->actingAsCustomer($user)->patchJson("/api/v1/addresses/{$theirs->id}", ['city' => 'Hacked'])->assertNotFound();
        $this->actingAsCustomer($user)->patchJson("/api/v1/addresses/{$theirs->id}/default")->assertNotFound();
        $this->actingAsCustomer($user)->deleteJson("/api/v1/addresses/{$theirs->id}")->assertNotFound();

        $this->assertNotSame('Hacked', $theirs->fresh()->city);
        $this->assertTrue(Address::whereKey($theirs->id)->exists());
    }

    // ------------------------------------------------------------------- snapshots

    public function test_order_address_is_a_snapshot_that_survives_address_edits(): void
    {
        [$user, $address] = $this->shopper();
        $this->cartWith($user);
        $id = $this->placeOrder($user, $address)->assertCreated()->json('data.id');
        $before = $address->line1;

        $this->actingAsCustomer($user)->patchJson("/api/v1/addresses/{$address->id}", [
            'line1' => '999 Brand New Road', 'city' => 'Jodhpur', 'pincode' => '342001',
        ])->assertOk();

        $this->assertSame('999 Brand New Road', $address->fresh()->line1);                     // the address book changed...
        $shown = $this->actingAsCustomer($user)->getJson("/api/v1/orders/{$id}")->assertOk();
        $this->assertSame($before, $shown->json('data.address.line1'));                        // ...the order did not
        $this->assertNotSame('Jodhpur', $shown->json('data.address.city'));
    }

    public function test_order_address_survives_deleting_the_saved_address(): void
    {
        [$user, $address] = $this->shopper();
        $this->cartWith($user);
        $id = $this->placeOrder($user, $address)->assertCreated()->json('data.id');

        $this->actingAsCustomer($user)->deleteJson("/api/v1/addresses/{$address->id}")->assertOk();

        $this->assertFalse(Address::whereKey($address->id)->exists());
        $this->assertSame(1, OrderAddress::where('order_id', $id)->count());
        $this->actingAsCustomer($user)->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('data.address.line1', $address->line1);
    }

    public function test_order_address_model_refuses_updates(): void
    {
        [$user, $address] = $this->shopper();
        $this->cartWith($user);
        $id = $this->placeOrder($user, $address)->assertCreated()->json('data.id');

        $snapshot = OrderAddress::where('order_id', $id)->firstOrFail();
        $snapshot->city = 'Changed';

        $this->expectException(LogicException::class);
        try {
            $snapshot->save();
        } finally {
            $this->assertNotSame('Changed', OrderAddress::where('order_id', $id)->value('city'));
        }
    }

    public function test_each_order_has_exactly_one_snapshot_row(): void
    {
        [$user, $address] = $this->shopper();
        $this->cartWith($user);
        $id = $this->placeOrder($user, $address)->assertCreated()->json('data.id');

        $this->assertSame(1, OrderAddress::where('order_id', $id)->count());
    }

    // ------------------------------------------------------- default maintenance

    public function test_deleting_the_default_address_promotes_another_and_keeps_exactly_one_default(): void
    {
        [$user, $default, $second] = $this->customerWithTwoAddresses();

        $this->actingAsCustomer($user)->deleteJson("/api/v1/addresses/{$default->id}")->assertOk();

        $this->assertSame(1, Address::where('user_id', $user->id)->where('is_default', true)->count());
        $this->assertTrue($second->fresh()->is_default);
        // a checkout with no selection now uses the promoted address
        $this->cartWith($user);
        $this->placeOrder($user, $second, 'cod', null, ['payment_method' => 'cod'])->assertCreated()->assertJsonPath('data.address.line1', '22 Second Street');
    }

    public function test_changing_the_default_moves_it_and_never_leaves_two(): void
    {
        [$user, $default, $second] = $this->customerWithTwoAddresses();

        $this->actingAsCustomer($user)->patchJson("/api/v1/addresses/{$second->id}/default")->assertOk();

        $this->assertSame(1, Address::where('user_id', $user->id)->where('is_default', true)->count());
        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($default->fresh()->is_default);
    }

    // ------------------------------------------------------------- idempotent retries

    public function test_retrying_with_the_same_key_creates_one_order_and_replays_the_response(): void
    {
        [$user, $address] = $this->shopper();
        $p = $this->cartWith($user);

        $first = $this->placeOrder($user, $address, 'cod', 'retry-key-0001')->assertCreated();
        $again = $this->placeOrder($user, $address, 'cod', 'retry-key-0001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame($first->json('data.order_number'), $again->json('data.order_number'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderAddress::count());
        $this->assertSame([8, 2], $this->stock($p, 'M'));                   // reserved ONCE: 10 sellable -> 8 sellable + 2 reserved (not 6 + 4)
    }

    public function test_replay_works_even_though_the_cart_is_now_empty(): void
    {
        [$user, $address] = $this->shopper();
        $this->cartWith($user);
        $this->placeOrder($user, $address, 'cod', 'retry-key-0002')->assertCreated();

        // the cart was cleared by the first call; a brand new key must fail, the old key must still replay
        $this->placeOrder($user, $address, 'cod', 'a-different-key-1')->assertStatus(422);
        $this->placeOrder($user, $address, 'cod', 'retry-key-0002')->assertCreated();
        $this->assertSame(1, Order::count());
    }

    public function test_same_key_for_a_different_address_after_success_is_refused(): void
    {
        [$user, $default, $second] = $this->customerWithTwoAddresses();
        $this->cartWith($user);
        $this->placeOrder($user, $default, 'cod', 'retry-key-0003')->assertCreated();

        $this->placeOrder($user, $second, 'cod', 'retry-key-0003')->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(1, Order::count());
    }

    public function test_retry_with_default_fallback_replays_the_same_order(): void
    {
        [$user] = $this->shopper();
        $this->cartWith($user);
        $body = ['payment_method' => 'cod'];

        $a = $this->placeOrder($user, new Address(), 'cod', 'retry-key-0004', $body)->assertCreated();
        $b = $this->placeOrder($user, new Address(), 'cod', 'retry-key-0004', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Order::count());
    }

    public function test_a_failed_attempt_leaves_no_order_and_the_same_key_can_be_retried(): void
    {
        [$user, $address] = $this->shopper();
        $p = $this->makeProduct(['M' => 1], ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($user, $p, 'M', 1)->assertSuccessful();
        \App\Models\ProductSize::query()->where('product_id', $p->id)->update(['quantity' => 0]);   // sold out meanwhile

        $this->placeOrder($user, $address, 'cod', 'retry-key-0005')->assertStatus(409);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderAddress::count());

        \App\Models\ProductSize::query()->where('product_id', $p->id)->update(['quantity' => 5]);    // restocked
        $this->placeOrder($user, $address, 'cod', 'retry-key-0005')->assertCreated();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, IdempotencyKey::where('idempotency_key', 'retry-key-0005')->count());
    }

    public function test_the_idempotency_key_header_is_mandatory_and_validated(): void
    {
        [$user, $address] = $this->shopper();
        $this->cartWith($user);

        $this->actingAsCustomer($user)->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertStatus(422);
        $this->placeOrder($user, $address, 'cod', 'bad key!')->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_two_customers_may_use_the_same_idempotency_key_independently(): void
    {
        [$u1, $a1] = $this->shopper();
        [$u2, $a2] = $this->shopper();
        $this->cartWith($u1);
        $this->cartWith($u2);

        $o1 = $this->placeOrder($u1, $a1, 'cod', 'shared-key-0001')->assertCreated()->json('data.id');
        $o2 = $this->placeOrder($u2, $a2, 'cod', 'shared-key-0001')->assertCreated()->json('data.id');

        $this->assertNotSame($o1, $o2);
        $this->assertSame(2, Order::count());
    }
}
