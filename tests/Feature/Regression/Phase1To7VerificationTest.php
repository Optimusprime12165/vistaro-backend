<?php

namespace Tests\Feature\Regression;

use App\Contracts\OtpDeliveryInterface;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/**
 * Independent verification added during the Phase 7 sign-off. It exercises real HTTP routes for Phases 1-6 (auth, OTP,
 * catalogue, cart, checkout, addresses) and the Phase 7 rules the original suite does not cover, so a regression in
 * earlier phases would show up here even though the original Phase 1-6 test files were not supplied.
 */
class Phase1To7VerificationTest extends TestCase
{
    use RefreshDatabase;
    use FulfilmentTestHelpers;

    /** @var list<string> */
    private array $sentCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sentCodes = [];
        $this->app->instance(OtpDeliveryInterface::class, new class($this->sentCodes) implements OtpDeliveryInterface {
            public function __construct(private array &$codes)
            {
            }

            public function send(string $mobile, string $otp, string $purpose): void
            {
                $this->codes[] = $otp;
            }
        });
    }

    // ------------------------------------------------------------------ Phase 4: admin auth + separation

    public function test_admin_login_me_logout_and_principal_separation(): void
    {
        $admin = Admin::factory()->create(['email' => 'boss@vistaro.local', 'password' => 'ChangeMe@123']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'boss@vistaro.local', 'password' => 'wrong'])->assertStatus(401);
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'boss@vistaro.local', 'password' => 'ChangeMe@123'])
            ->assertOk()->json('data.token');
        $this->assertNotEmpty($token);

        $this->guest()->withToken($token)->getJson('/api/v1/admin/me')->assertOk();

        // an admin token is not a customer, a customer token is not an admin
        $this->assertContains($this->guest()->withToken($token)->getJson('/api/v1/me')->status(), [401, 403]);
        [$user] = $this->shopper();
        $this->assertContains($this->actingAsCustomer($user)->getJson('/api/v1/admin/me')->status(), [401, 403]);
        $this->assertContains($this->actingAsCustomer($user)->getJson('/api/v1/admin/orders')->status(), [401, 403]);
        $this->guest()->getJson('/api/v1/admin/orders')->assertStatus(401);

        $this->guest()->withToken($token)->postJson('/api/v1/admin/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->guest()->withToken($token)->getJson('/api/v1/admin/me')->assertStatus(401);
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_inactive_admin_cannot_use_a_valid_token(): void
    {
        $admin = Admin::factory()->create();
        $token = $this->actingAsAdmin(); // issues for a different admin; build ours explicitly
        $plain = app(\App\Services\Auth\TokenService::class)->issue($admin, 'admin', 60, 't')->plainTextToken;
        $admin->forceFill(['is_active' => false])->save();
        $this->guest()->withToken($plain)->getJson('/api/v1/admin/me')->assertStatus(403);
    }

    // ------------------------------------------------------------------ Phase 4: customer OTP

    public function test_otp_flow_cooldowns_daily_limit_and_verification(): void
    {
        $mobile = '9876543210';

        $r = $this->postJson('/api/v1/auth/otp/request', ['mobile' => $mobile])->assertOk();
        $this->assertStringNotContainsString($this->sentCodes[0], $r->getContent(), 'OTP must never be in the response');

        $this->postJson('/api/v1/auth/otp/resend', ['mobile' => $mobile])->assertStatus(429);          // inside the 30 s cooldown
        $this->travel(32)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['mobile' => $mobile])->assertOk();                   // issuance #2
        $this->travel(40)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['mobile' => $mobile])->assertStatus(429);          // 60 s cooldown not over
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['mobile' => $mobile])->assertOk();                   // issuance #3
        $this->travel(120)->seconds();
        $blocked = $this->postJson('/api/v1/auth/otp/request', ['mobile' => $mobile])->assertStatus(429); // 4th is refused
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertCount(3, $this->sentCodes);

        // old OTPs are dead, only the latest works
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile, 'otp' => $this->sentCodes[0] === $this->sentCodes[2] ? '000000' : $this->sentCodes[0]])->assertStatus(422);
        $ok = $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile, 'otp' => $this->sentCodes[2]])->assertOk();
        $this->assertNotEmpty($ok->json('data.token'));
        $this->assertTrue($ok->json('data.is_new_user'));
        $this->assertDatabaseHas('users', ['mobile' => '+919876543210']);
        // single use
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile, 'otp' => $this->sentCodes[2]])->assertStatus(422);
    }

    public function test_otp_expires_and_wrong_attempts_burn_it(): void
    {
        $mobile = '9123456780';
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => $mobile])->assertOk();
        $good = $this->sentCodes[0];
        $bad = $good === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile, 'otp' => $bad])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile, 'otp' => $bad])->assertStatus(429);   // 5th wrong burns it
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile, 'otp' => $good])->assertStatus(422);   // even the right code is dead now

        $mobile2 = '9000000001';
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => $mobile2])->assertOk();
        $this->travel(301)->seconds();
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $mobile2, 'otp' => end($this->sentCodes)])->assertStatus(422);
    }

    // ------------------------------------------------------------------ Phase 5: catalogue + pagination

    public function test_public_catalogue_pagination_stock_display_and_visibility(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeProduct(['M' => 50]);
        }
        $few = $this->makeProduct(['M' => 8, 'L' => 0, 'S' => 40]);

        $r = $this->guest()->getJson('/api/v1/products')->assertOk();
        $this->assertCount(10, $r->json('data'), 'default page size is 10');
        $this->assertSame(10, $r->json('meta.per_page'));
        $this->assertCount(20, $this->getJson('/api/v1/products?per_page=20')->assertOk()->json('data'));
        $this->getJson('/api/v1/products?per_page=101')->assertStatus(422);                              // hard maximum
        $this->getJson('/api/v1/products?page=2')->assertOk();

        $detail = $this->getJson('/api/v1/products/' . $few->slug)->assertOk();
        $json = json_encode($detail->json('data'));
        $sizes = collect($detail->json('data.sizes'))->keyBy('size');
        $this->assertSame(8, $sizes['M']['left']);                       // 10 or fewer: exact count shown
        $this->assertNull($sizes['S']['left']);                          // 40 in stock: exact number hidden
        $this->assertTrue($sizes['S']['available']);
        $this->assertFalse($sizes['L']['available']);                    // zero stock: disabled
        $this->assertStringNotContainsString('40', json_encode(array_map(fn ($s) => $s['left'], $detail->json('data.sizes'))));
    }

    public function test_inactive_product_is_hidden_from_the_storefront(): void
    {
        $p = $this->makeProduct(['M' => 5], ['status' => 'draft']);
        $this->getJson('/api/v1/products/' . $p->slug)->assertStatus(404);
    }

    // ------------------------------------------------------------------ Phase 5: admin catalogue

    public function test_admin_catalogue_crud_permissions_and_low_stock(): void
    {
        $cat = $this->actingAsAdmin()->postJson('/api/v1/admin/categories', ['name' => 'Verify Tees'])->assertCreated()->json('data.id');
        $sub = $this->actingAsAdmin()->postJson('/api/v1/admin/sub-categories', ['category_id' => $cat, 'name' => 'Round neck'])->assertCreated()->json('data.id');

        $payload = [
            'sub_category_id' => $sub, 'name' => 'Verify Tee', 'sku' => 'VER-001', 'price' => '999.00', 'mrp' => '1299.00',
            'attributes' => ['fabric' => 'Cotton', 'fit' => 'Regular'],
            'sizes' => [['size' => 'M', 'quantity' => 3], ['size' => 'L', 'quantity' => 0], ['size' => 'XL', 'quantity' => 30]],
        ];
        $this->actingAsAdmin(AdminRole::Staff)->postJson('/api/v1/admin/products', $payload)->assertStatus(403);
        $pid = $this->actingAsAdmin()->postJson('/api/v1/admin/products', $payload)->assertCreated()->json('data.id');
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', $payload)->assertStatus(422);        // duplicate SKU
        $this->actingAsAdmin()->postJson('/api/v1/admin/products', ['sku' => 'VER-002', 'price' => '2000.00'] + $payload)->assertStatus(422);   // MRP below price

        $low = $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/inventory/low-stock')->assertOk();
        $this->assertStringContainsString('VER-001', $low->getContent());
        $adj = $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/products/{$pid}/stock/adjust", ['size' => 'M', 'delta' => -5, 'note' => 'sold in the shop']);
        $this->assertContains($adj->status(), [409, 422], 'stock can never go negative');
        $this->assertSame(3, (int) ProductSize::where(['product_id' => $pid, 'size' => 'M'])->value('quantity'));

        // deleting a category that still has sub-categories must not orphan anything
        $del = $this->actingAsAdmin()->deleteJson("/api/v1/admin/categories/{$cat}");
        $this->assertContains($del->status(), [409, 422]);
        $this->assertDatabaseHas('sub_categories', ['id' => $sub]);
    }

    public function test_admin_list_pagination_limits(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->newOrder(1);
        }
        $r = $this->actingAsAdmin()->getJson('/api/v1/admin/orders')->assertOk();
        $this->assertCount(10, $r->json('data'));
        $this->assertTrue($r->json('meta.has_more'));
        $twenty = $this->actingAsAdmin()->getJson('/api/v1/admin/orders?per_page=20')->assertOk();
        $this->assertCount(12, $twenty->json('data'));
        $this->assertSame(20, $twenty->json('meta.per_page'));
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?per_page=101')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?sort=password')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/orders?status=not_a_status')->assertStatus(422);
        $this->actingAsAdmin()->getJson("/api/v1/admin/orders?q=' OR 1=1 --")->assertOk();                // SQL-ish text is just text
        $this->actingAsAdmin()->getJson('/api/v1/admin/customers?per_page=101')->assertStatus(422);
    }

    // ------------------------------------------------------------------ Phase 6: cart, pricing, overselling

    public function test_cart_is_server_priced_and_refuses_overselling(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 3], ['price' => '500.00', 'mrp' => '700.00']);

        $this->actingAsCustomer($user)->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'size' => 'M', 'quantity' => 2, 'price' => '1.00', 'total' => '1.00'])->assertSuccessful();
        $cart = $this->actingAsCustomer($user)->getJson('/api/v1/cart')->assertOk();
        $this->assertSame('1000.00', $cart->json('data.totals.subtotal'), 'client supplied price must be ignored');
        $this->assertSame('99.00', $cart->json('data.totals.shipping_fee'));
        $this->assertSame('1099.00', $cart->json('data.totals.total'));

        $itemId = $cart->json('data.items.0.id');
        $this->assertContains($this->actingAsCustomer($user)->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 4])->status(), [409, 422]);   // only 3 in stock
        $this->actingAsCustomer($user)->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 3])->assertOk();
        $this->actingAsCustomer($user)->deleteJson("/api/v1/cart/items/{$itemId}")->assertSuccessful();
        $this->actingAsCustomer($user)->deleteJson('/api/v1/cart')->assertSuccessful();
        $this->assertSame(0, (int) $this->actingAsCustomer($user)->getJson('/api/v1/cart')->json('data.totals.item_count'));
    }

    public function test_last_unit_goes_to_exactly_one_buyer(): void
    {
        $p = $this->makeProduct(['M' => 1], ['price' => '2500.00', 'mrp' => '2500.00']);
        [$a, $addrA] = $this->shopper();
        [$b, $addrB] = $this->shopper();
        $this->addToCart($a, $p, 'M', 1)->assertSuccessful();
        $this->addToCart($b, $p, 'M', 1)->assertSuccessful();

        $this->placeOrder($a, $addrA)->assertCreated();
        $second = $this->placeOrder($b, $addrB);
        $this->assertContains($second->status(), [409, 422]);
        $this->assertSame(1, Order::count());
        $this->assertSame([0, 1], $this->stock($p, 'M'));                                                  // none left, one reserved
    }

    // ------------------------------------------------------------------ Phase 6/7: ownership

    public function test_customers_cannot_see_or_change_each_others_orders_or_addresses(): void
    {
        [$order, , $owner] = $this->newOrder(1);
        [$intruder, $intruderAddress] = $this->shopper();
        $ownerAddressId = $owner->addresses()->first()->id;

        $this->actingAsCustomer($intruder)->getJson("/api/v1/orders/{$order->id}")->assertStatus(404);
        $this->actingAsCustomer($intruder)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'because'])->assertStatus(404);
        $this->actingAsCustomer($intruder)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'because'])->assertStatus(404);
        $this->assertNotContains($order->id, collect($this->actingAsCustomer($intruder)->getJson('/api/v1/orders')->json('data'))->pluck('id')->all());
        $this->actingAsCustomer($intruder)->getJson("/api/v1/addresses/{$ownerAddressId}")->assertStatus(404);
        $this->actingAsCustomer($intruder)->patchJson("/api/v1/addresses/{$ownerAddressId}/default")->assertStatus(404);
        $this->actingAsCustomer($intruder)->deleteJson("/api/v1/addresses/{$ownerAddressId}")->assertStatus(404);
        $this->assertSame('pending', $this->statusOf($order->id));
    }

    // ------------------------------------------------------------------ Phase 7: settings drive shipping and returns end to end

    public function test_admin_changing_shipping_settings_changes_the_next_checkout_and_staff_cannot(): void
    {
        $this->seed(\Database\Seeders\BusinessSettingsSeeder::class);
        [$user, $address] = $this->shopper();
        $p = $this->makeProduct(['M' => 20], ['price' => '1000.00', 'mrp' => '1000.00']);
        $this->addToCart($user, $p, 'M', 1)->assertSuccessful();
        $this->assertSame('99.00', $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview')->json('data.totals.shipping_fee'));

        $this->actingAsAdmin(AdminRole::Staff)->patchJson('/api/v1/admin/settings/shipping.flat_fee', ['value' => 10])->assertStatus(403);
        $this->actingAsAdmin()->patchJson('/api/v1/admin/settings/shipping.flat_fee', ['value' => 149])->assertOk();
        $this->assertSame('149.00', $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview')->json('data.totals.shipping_fee'));

        $this->actingAsAdmin()->patchJson('/api/v1/admin/settings/shipping.free_above', ['value' => 900])->assertOk();
        $this->assertSame('0.00', $this->actingAsCustomer($user)->getJson('/api/v1/checkout/preview')->json('data.totals.shipping_fee'));

        $id = $this->placeOrder($user, $address)->assertCreated()->json('data.id');
        $this->actingAsAdmin()->patchJson('/api/v1/admin/settings/shipping.free_above', ['value' => 5000])->assertOk();
        $this->assertSame('0.00', number_format((float) Order::findOrFail($id)->shipping_fee, 2, '.', ''), 'an existing order keeps the fee it was placed with');
    }

    public function test_full_cod_lifecycle_return_window_setting_and_refund(): void
    {
        [$order, $p, $user] = $this->newOrder(2, 'cod', ['M' => 10]);
        $this->assertSame([8, 2], $this->stock($p, 'M'));            // [sellable, reserved]
        $this->seed(\Database\Seeders\BusinessSettingsSeeder::class);
        $this->actingAsAdmin()->patchJson('/api/v1/admin/settings/orders.return_window_days', ['value' => 3])->assertOk();
        $this->delivered($order->id);
        $this->assertSame([8, 0], $this->stock($p, 'M'), 'reservation consumed on shipping');
        $order->refresh();
        $this->assertSame('paid', $order->payment_status->value, 'COD becomes paid on delivery');

        $this->travel(4)->days();
        $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'too small'])->assertStatus(422);
        $this->travelBack();

        $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'too small'])->assertSuccessful();
        $this->move($order->id, 'returned', ['restock' => true])->assertOk();
        $this->assertSame([10, 0], $this->stock($p, 'M'));
        $this->move($order->id, 'refund_pending')->assertOk();
        $this->actingAsAdmin(AdminRole::Staff)->postJson("/api/v1/admin/orders/{$order->id}/refund", ['reference' => 'R1'])->assertStatus(403);
        $this->actingAsAdmin()->postJson("/api/v1/admin/orders/{$order->id}/refund", ['reference' => 'R1'])->assertOk();
        $this->assertSame('refunded', $this->statusOf($order->id));
        $this->assertSame('refunded', Order::findOrFail($order->id)->payment_status->value);
        $this->assertGreaterThanOrEqual(10, \App\Models\OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_customer_cancel_releases_stock_and_is_refused_after_packing(): void
    {
        [$order, $p, $user] = $this->newOrder(3, 'cod', ['M' => 10]);
        $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'changed my mind'])->assertSuccessful();
        $this->assertSame([10, 0], $this->stock($p, 'M'));
        $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'again'])->assertStatus(422);

        [$o2, , $u2] = $this->newOrder(1);
        $this->pack($o2->id);
        $this->actingAsCustomer($u2)->postJson("/api/v1/orders/{$o2->id}/cancel", ['reason' => 'late'])->assertStatus(422);
    }

    public function test_idempotent_order_placement_creates_one_order_one_reservation(): void
    {
        [$user, $address] = $this->shopper();
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($user, $p, 'M', 2)->assertSuccessful();
        $first = $this->placeOrder($user, $address, 'cod', 'idem-key-12345');
        $first->assertCreated();
        $again = $this->placeOrder($user, $address, 'cod', 'idem-key-12345');
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, Order::count());
        $this->assertSame([8, 2], $this->stock($p, 'M'));
        $this->placeOrder($user, $address, 'cod', 'short')->assertStatus(422);                                // bad key format
    }

    // ------------------------------------------------------------------ every route: who may call it

    public function test_every_protected_route_rejects_guests_and_wrong_principal(): void
    {
        $public = [
            'GET api/v1/health', 'GET api/v1/ready', 'GET api/v1/categories', 'GET api/v1/categories/{slug}', 'GET api/v1/sub-categories',
            'GET api/v1/products', 'GET api/v1/products/facets', 'GET api/v1/products/{slug}', 'GET api/v1/products/{slug}/related',
            'GET api/v1/settings', 'GET api/v1/settings/{key}',
            'POST api/v1/auth/otp/request', 'POST api/v1/auth/otp/resend', 'POST api/v1/auth/otp/verify',
            'POST api/v1/admin/auth/login', 'POST api/v1/webhooks/delivery/{provider}',
        ];
        [$customer] = $this->shopper();
        $checked = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (in_array("$method $uri", $public, true)) {
                    continue;
                }
                $url = '/' . preg_replace('/\{[^}]+\}/', '1', $uri);
                $this->guest();
                $this->assertSame(401, $this->json($method, $url)->status(), "guest must get 401 on $method $uri");
                if (str_starts_with($uri, 'api/v1/admin/')) {
                    $code = $this->actingAsCustomer($customer)->json($method, $url)->status();
                    $this->assertContains($code, [401, 403], "customer token must not reach $method $uri");
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(60, $checked);
    }
}
