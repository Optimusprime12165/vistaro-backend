<?php

namespace Tests\Feature\Settings;

use App\Enums\AdminRole;
use App\Models\BusinessSetting;
use App\Services\Settings\BusinessSettingsService;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FulfilmentTestHelpers;
use Tests\TestCase;

/**
 * Business Settings: admin CRUD + authorization, public/private visibility, type validation, duplicate keys, cache
 * invalidation, shipping calculation, return-window eligibility and safe fallbacks.
 */
class BusinessSettingsApiTest extends TestCase
{
    use FulfilmentTestHelpers, RefreshDatabase;

    private const ADMIN = '/api/v1/admin/settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSettingsSeeder::class);
    }

    private function svc(): BusinessSettingsService
    {
        return app(BusinessSettingsService::class);
    }

    /** Bypasses the service on purpose (simulates a bad manual DB edit), then drops the cache so the app re-reads. */
    private function rawSet(string $key, array $attrs): void
    {
        BusinessSetting::query()->where('key', $key)->update($attrs);
        $this->svc()->flush();
    }

    private function create(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsAdmin()->postJson(self::ADMIN, $over + [
            'key' => 'store.tagline', 'type' => 'string', 'value' => 'Fashion, simplified', 'group' => 'store', 'is_public' => true,
        ]);
    }

    // ------------------------------------------------------------ authorization

    public function test_every_admin_settings_endpoint_requires_an_admin_token(): void
    {
        $this->guest()->getJson(self::ADMIN)->assertStatus(401);
        $this->guest()->getJson(self::ADMIN . '/shipping.flat_fee')->assertStatus(401);
        $this->guest()->postJson(self::ADMIN, [])->assertStatus(401);
        $this->guest()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 1])->assertStatus(401);
        $this->guest()->deleteJson(self::ADMIN . '/shipping.flat_fee')->assertStatus(401);
    }

    public function test_a_customer_token_cannot_use_admin_settings(): void
    {
        [$user] = $this->shopper();

        $this->actingAsCustomer($user)->getJson(self::ADMIN)->assertStatus(401);
        $this->actingAsCustomer($user)->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 1])->assertStatus(401);
        $this->assertSame(99, $this->svc()->shippingFlatFee());
    }

    public function test_staff_are_forbidden_but_admin_and_super_admin_are_allowed(): void
    {
        $this->actingAsAdmin(AdminRole::Staff)->getJson(self::ADMIN)->assertStatus(403);
        $this->actingAsAdmin(AdminRole::Staff)->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 1])->assertStatus(403);
        $this->assertSame(99, $this->svc()->shippingFlatFee());

        $this->actingAsAdmin(AdminRole::SuperAdmin)->getJson(self::ADMIN)->assertOk();
        $this->actingAsAdmin(AdminRole::SuperAdmin)->getJson(self::ADMIN)->assertOk();
    }

    // --------------------------------------------------------------------- CRUD

    public function test_admin_can_create_read_update_and_delete_a_setting(): void
    {
        $this->create()->assertCreated()->assertJsonPath('data.key', 'store.tagline')->assertJsonPath('data.value', 'Fashion, simplified');

        $this->actingAsAdmin()->getJson(self::ADMIN . '/store.tagline')->assertOk()->assertJsonPath('data.type', 'string');

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/store.tagline', ['value' => 'New tagline', 'description' => 'Shown in the footer'])
            ->assertOk()->assertJsonPath('data.value', 'New tagline')->assertJsonPath('data.description', 'Shown in the footer');

        $this->actingAsAdmin()->deleteJson(self::ADMIN . '/store.tagline')->assertOk();
        $this->actingAsAdmin()->getJson(self::ADMIN . '/store.tagline')->assertNotFound();
        $this->assertDatabaseMissing('business_settings', ['key' => 'store.tagline']);
    }

    public function test_updating_or_deleting_a_missing_setting_is_404(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/no.such_key', ['value' => 'x'])->assertNotFound();
        $this->actingAsAdmin()->deleteJson(self::ADMIN . '/no.such_key')->assertNotFound();
    }

    public function test_listing_is_paginated_filterable_and_sorted_by_key(): void
    {
        foreach (['store.a_one', 'store.b_two', 'store.c_three'] as $k) {
            $this->create(['key' => $k, 'value' => $k])->assertCreated();
        }

        $all = $this->actingAsAdmin()->getJson(self::ADMIN)->assertOk();
        $this->assertSame(10, $all->json('meta.per_page'));
        $this->assertSame(6, $all->json('meta.total'));                                    // 3 seeded + 3 created
        $keys = collect($all->json('data'))->pluck('key')->all();
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);

        $store = $this->actingAsAdmin()->getJson(self::ADMIN . '?group=store&per_page=2')->assertOk();
        $this->assertSame(3, $store->json('meta.total'));
        $this->assertCount(2, $store->json('data'));
        $this->assertSame(2, $store->json('meta.last_page'));

        $this->actingAsAdmin()->getJson(self::ADMIN . '?is_public=0')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsAdmin()->getJson(self::ADMIN . '?group=Bad%20Group')->assertStatus(422);
    }

    public function test_the_known_business_rule_settings_cannot_be_deleted(): void
    {
        foreach (['shipping.flat_fee', 'shipping.free_above', 'orders.return_window_days'] as $key) {
            $this->actingAsAdmin()->deleteJson(self::ADMIN . "/{$key}")->assertStatus(422)->assertJsonPath('success', false);
            $this->assertDatabaseHas('business_settings', ['key' => $key]);
        }
    }

    // -------------------------------------------------------------- visibility

    public function test_public_api_shows_only_public_settings_and_hides_private_ones_completely(): void
    {
        $this->create(['key' => 'store.support_email', 'type' => 'email', 'value' => 'help@vistaro.test', 'is_public' => true])->assertCreated();
        $this->create(['key' => 'store.internal_note', 'type' => 'string', 'value' => 'warehouse is closed on Sunday', 'group' => 'store', 'is_public' => false])->assertCreated();

        $res = $this->guest()->getJson('/api/v1/settings')->assertOk();
        $data = $res->json('data');                                                       // group => [full key => value]
        $this->assertSame('help@vistaro.test', $data['store']['store.support_email']);
        $this->assertSame(99, $data['shipping']['shipping.flat_fee']);
        $this->assertStringNotContainsString('internal_note', $res->getContent());
        $this->assertStringNotContainsString('warehouse', $res->getContent());

        $this->guest()->getJson('/api/v1/settings/store.support_email')->assertOk()->assertJsonPath('data.value', 'help@vistaro.test');
        // a private key is indistinguishable from a missing one
        $private = $this->guest()->getJson('/api/v1/settings/store.internal_note')->assertNotFound();
        $missing = $this->guest()->getJson('/api/v1/settings/store.does_not_exist')->assertNotFound();
        $this->assertSame($missing->json('message'), $private->json('message'));

        // the admin still sees it
        $this->actingAsAdmin()->getJson(self::ADMIN . '/store.internal_note')->assertOk()->assertJsonPath('data.is_public', false);
    }

    public function test_public_group_filter_and_validation(): void
    {
        $res = $this->guest()->getJson('/api/v1/settings?group=orders')->assertOk();
        $this->assertSame(['orders'], array_keys($res->json('data')));
        $this->guest()->getJson('/api/v1/settings?group=NOPE!')->assertStatus(422);
    }

    public function test_toggling_is_public_hides_and_reveals_the_setting_publicly(): void
    {
        $this->create(['key' => 'store.promo_text', 'value' => 'Sale', 'is_public' => true])->assertCreated();
        $this->guest()->getJson('/api/v1/settings/store.promo_text')->assertOk();

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/store.promo_text', ['is_public' => false])->assertOk();
        $this->guest()->getJson('/api/v1/settings/store.promo_text')->assertNotFound();
    }

    public function test_public_endpoints_need_no_login_and_are_read_only(): void
    {
        $this->guest()->getJson('/api/v1/settings')->assertOk();
        $this->guest()->postJson('/api/v1/settings', ['key' => 'x'])->assertStatus(405);
        $this->guest()->deleteJson('/api/v1/settings/shipping.flat_fee')->assertStatus(405);
    }

    // ----------------------------------------------------------- type validation

    /** @return array<string,array{0:string,1:mixed}> */
    public static function invalidValues(): array
    {
        return [
            'integer from text' => ['integer', 'abc'],
            'integer from float' => ['integer', 12.5],
            'integer from array' => ['integer', [1]],
            'decimal from text' => ['decimal', 'ten'],
            'boolean from text' => ['boolean', 'maybe'],
            'email invalid' => ['email', 'not-an-email'],
            'url invalid' => ['url', 'not a url'],
            'json from scalar' => ['json', 5],
            'json broken text' => ['json', '{broken'],
            'string from array' => ['string', ['a']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function test_values_that_do_not_match_the_declared_type_are_rejected(string $type, mixed $value): void
    {
        $this->create(['key' => 'test.typed_value', 'type' => $type, 'value' => $value, 'group' => 'test'])
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseMissing('business_settings', ['key' => 'test.typed_value']);
    }

    public function test_valid_values_are_stored_in_canonical_form_and_returned_typed(): void
    {
        $cases = [
            ['integer', 42, 42, '42'],
            ['decimal', '10.50', '10.5', '10.5'],
            ['boolean', true, true, '1'],
            ['boolean', false, false, '0'],
            ['email', 'a@b.test', 'a@b.test', 'a@b.test'],
            ['url', 'https://vistaro.test/help', 'https://vistaro.test/help', 'https://vistaro.test/help'],
            ['json', ['a' => 1, 'b' => [true]], ['a' => 1, 'b' => [true]], '{"a":1,"b":[true]}'],
            ['string', '+91 0987', '+91 0987', '+91 0987'],
        ];
        foreach ($cases as $i => [$type, $in, $out, $stored]) {
            $key = "types.case_{$i}";
            $this->create(['key' => $key, 'type' => $type, 'value' => $in, 'group' => 'types'])->assertCreated()->assertJsonPath('data.value', $out);
            $this->assertSame($stored, DB::table('business_settings')->where('key', $key)->value('value'), "{$type} stored form");
        }
    }

    public function test_unsupported_type_and_malformed_keys_are_rejected(): void
    {
        $this->create(['key' => 'x.bad_type', 'type' => 'float'])->assertStatus(422);
        foreach (['Upper.Case', '1starts_with_digit', 'has space', 'a..b', 'trailing.', str_repeat('a', 101)] as $key) {
            $this->create(['key' => $key])->assertStatus(422);
        }
        $this->assertSame(3, BusinessSetting::count());
    }

    public function test_type_and_key_cannot_be_changed_by_update(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['type' => 'string', 'value' => 'free'])->assertStatus(422);
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['key' => 'shipping.other', 'value' => 5])->assertStatus(422);
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', [])->assertStatus(422);
        $this->assertSame(99, $this->svc()->shippingFlatFee());
    }

    public function test_business_rule_settings_enforce_their_type_and_range(): void
    {
        foreach ([['shipping.flat_fee', -1], ['shipping.flat_fee', 100001], ['shipping.flat_fee', 'abc'], ['orders.return_window_days', 366], ['orders.return_window_days', -5], ['shipping.free_above', 10000001]] as [$k, $v]) {
            $this->actingAsAdmin()->patchJson(self::ADMIN . "/{$k}", ['value' => $v])->assertStatus(422);
        }
        $this->assertSame(99, $this->svc()->shippingFlatFee());
        $this->assertSame(14, $this->svc()->returnWindowDays());

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/orders.return_window_days', ['value' => 0])->assertOk();   // boundary values are fine
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/orders.return_window_days', ['value' => 365])->assertOk();
    }

    public function test_secrets_are_refused(): void
    {
        $this->create(['key' => 'store.api_key', 'value' => 'abc'])->assertStatus(422);
        $this->create(['key' => 'store.db_password', 'value' => 'abc'])->assertStatus(422);
        $this->create(['key' => 'store.note', 'value' => 'AKIAABCDEFGHIJKLMNOP'])->assertStatus(422);
        $this->create(['key' => 'store.blob', 'type' => 'json', 'value' => ['client_secret' => 'x']])->assertStatus(422);
        $this->assertDatabaseMissing('business_settings', ['key' => 'store.note']);
    }

    // ------------------------------------------------------------ duplicate keys

    public function test_duplicate_keys_are_refused_by_the_api_and_the_original_is_untouched(): void
    {
        $this->create(['value' => 'first'])->assertCreated();

        $this->create(['value' => 'second'])->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['key']]);
        $this->create(['key' => 'shipping.flat_fee', 'type' => 'integer', 'value' => 5, 'group' => 'shipping'])->assertStatus(422);

        $this->assertSame(1, BusinessSetting::where('key', 'store.tagline')->count());
        $this->assertSame('first', $this->svc()->get('store.tagline'));
        $this->assertSame(99, $this->svc()->shippingFlatFee());
    }

    public function test_the_database_itself_rejects_a_duplicate_key(): void
    {
        $this->expectException(QueryException::class);
        DB::table('business_settings')->insert(['key' => 'shipping.flat_fee', 'value' => '1', 'type' => 'integer', 'group' => 'shipping', 'is_public' => true]);
    }

    public function test_seeder_is_idempotent_and_never_overwrites_admin_changes(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 150])->assertOk();

        $this->seed(BusinessSettingsSeeder::class);
        $this->seed(BusinessSettingsSeeder::class);

        $this->assertSame(3, BusinessSetting::count());
        $this->assertSame(150, $this->svc()->shippingFlatFee());
    }

    // ------------------------------------------------------- cache invalidation

    public function test_admin_writes_are_visible_immediately_through_the_cached_public_api(): void
    {
        $this->guest()->getJson('/api/v1/settings/shipping.flat_fee')->assertOk()->assertJsonPath('data.value', 99);   // warms the cache
        $this->assertTrue(Cache::has('vistaro:business_settings:v1'));

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 120])->assertOk();
        $this->guest()->getJson('/api/v1/settings/shipping.flat_fee')->assertJsonPath('data.value', 120);

        $this->create(['key' => 'store.new_one', 'value' => 'hello'])->assertCreated();
        $this->guest()->getJson('/api/v1/settings/store.new_one')->assertOk();

        $this->actingAsAdmin()->deleteJson(self::ADMIN . '/store.new_one')->assertOk();
        $this->guest()->getJson('/api/v1/settings/store.new_one')->assertNotFound();
    }

    public function test_a_write_drops_the_cached_snapshot_and_reads_are_served_from_the_cache(): void
    {
        $this->svc()->get('shipping.flat_fee');
        $this->assertTrue(Cache::has('vistaro:business_settings:v1'));

        DB::table('business_settings')->where('key', 'shipping.flat_fee')->update(['value' => '77']);   // behind the app's back
        $this->assertSame(99, $this->svc()->shippingFlatFee(), 'cached snapshot still served');

        $this->svc()->flush();
        $this->assertFalse(Cache::has('vistaro:business_settings:v1'));
        $this->assertSame(77, $this->svc()->shippingFlatFee());

        $this->svc()->update('shipping.flat_fee', ['value' => 88]);                                      // service writes flush by themselves
        $this->assertSame(88, $this->svc()->shippingFlatFee());
    }

    public function test_a_warm_cache_costs_no_queries(): void
    {
        $this->svc()->get('shipping.flat_fee');
        Cache::forget('x');                                                                              // keep the snapshot, drop nothing else

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->svc()->shippingFlatFee();
        $this->svc()->freeShippingThreshold();
        $this->svc()->returnWindowDays();
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_a_failed_validation_leaves_the_cache_and_value_unchanged(): void
    {
        $this->guest()->getJson('/api/v1/settings/shipping.flat_fee')->assertOk();
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => -9])->assertStatus(422);
        $this->guest()->getJson('/api/v1/settings/shipping.flat_fee')->assertJsonPath('data.value', 99);
    }

    // -------------------------------------------------------------- shipping

    private function cartTotals(float|int $price, int $qty = 1): array
    {
        [$user, $address] = $this->shopper();
        $p = $this->makeProduct(['M' => 50], ['price' => number_format($price, 2, '.', ''), 'mrp' => number_format($price + 200, 2, '.', '')]);
        $this->addToCart($user, $p, 'M', $qty)->assertSuccessful();

        return $this->actingAsCustomer($user)->getJson('/api/v1/cart')->assertOk()->json('data.totals');
    }

    public function test_default_shipping_follows_the_seeded_settings(): void
    {
        $below = $this->cartTotals(1000);
        $this->assertSame('99.00', $below['shipping_fee']);
        $this->assertSame('1099.00', $below['total']);
        $this->assertSame('1999.00', $below['free_shipping_above']);
        $this->assertSame('999.00', $below['amount_for_free_shipping']);

        $free = $this->cartTotals(1000, 2);                                                              // 2000 >= 1999
        $this->assertSame('0.00', $free['shipping_fee']);
        $this->assertSame('2000.00', $free['total']);
    }

    public function test_changing_the_settings_changes_cart_totals_without_a_deploy(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 150])->assertOk();
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.free_above', ['value' => 2500])->assertOk();

        $t = $this->cartTotals(1000, 2);                                                                 // 2000 < 2500 now pays shipping
        $this->assertSame('150.00', $t['shipping_fee']);
        $this->assertSame('2150.00', $t['total']);
        $this->assertSame('2500.00', $t['free_shipping_above']);
        $this->assertSame('500.00', $t['amount_for_free_shipping']);
    }

    public function test_free_shipping_threshold_is_inclusive_and_zero_means_always_free(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.free_above', ['value' => 1000])->assertOk();
        $this->assertSame('0.00', $this->cartTotals(1000)['shipping_fee']);                              // exactly at the threshold

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.free_above', ['value' => 0])->assertOk();
        $this->assertSame('0.00', $this->cartTotals(10)['shipping_fee']);
    }

    public function test_a_flat_fee_of_zero_is_valid(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 0])->assertOk();
        $this->assertSame('0.00', $this->cartTotals(100)['shipping_fee']);
    }

    public function test_an_order_stores_the_shipping_fee_in_force_when_it_was_placed(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 150])->assertOk();
        [$user, $address] = $this->shopper();
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->addToCart($user, $p, 'M', 1)->assertSuccessful();
        $res = $this->placeOrder($user, $address)->assertCreated();
        $this->assertSame('150.00', $res->json('data.shipping_fee'));
        $this->assertSame('1150.00', $res->json('data.total'));

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/shipping.flat_fee', ['value' => 10])->assertOk();   // later change must not touch the order
        $this->actingAsCustomer($user)->getJson('/api/v1/orders/' . $res->json('data.id'))->assertJsonPath('data.shipping_fee', '150.00')->assertJsonPath('data.total', '1150.00');
    }

    // --------------------------------------------------------- return window

    private function deliveredOrderFor(): array
    {
        [$order, , $user] = $this->newOrder(1);
        $this->delivered($order->id);

        return [$order, $user];
    }

    private function requestReturn($user, int $orderId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsCustomer($user)->postJson("/api/v1/orders/{$orderId}/return", ['reason' => 'Does not fit properly']);
    }

    public function test_return_is_allowed_inside_the_default_14_day_window_and_refused_after_it(): void
    {
        [$order, $user] = $this->deliveredOrderFor();
        Carbon::setTestNow(now()->addDays(14)->subMinute());
        $this->requestReturn($user, $order->id)->assertOk();
        $this->assertSame('return_requested', $this->statusOf($order->id));

        [$late, $user2] = $this->deliveredOrderFor();
        Carbon::setTestNow(now()->addDays(15));
        $this->requestReturn($user2, $late->id)->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('delivered', $this->statusOf($late->id));
    }

    public function test_the_return_window_follows_the_setting_immediately(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/orders.return_window_days', ['value' => 3])->assertOk();
        [$order, $user] = $this->deliveredOrderFor();

        Carbon::setTestNow(now()->addDays(4));
        $this->requestReturn($user, $order->id)->assertStatus(422);                                      // closed under the 3-day rule

        $this->actingAsAdmin()->patchJson(self::ADMIN . '/orders.return_window_days', ['value' => 10])->assertOk();
        $this->requestReturn($user, $order->id)->assertOk();                                             // same order, window widened
    }

    public function test_a_zero_day_window_allows_returns_only_at_the_moment_of_delivery(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/orders.return_window_days', ['value' => 0])->assertOk();
        [$order, $user] = $this->deliveredOrderFor();

        Carbon::setTestNow(now()->addMinutes(5));
        $this->requestReturn($user, $order->id)->assertStatus(422);
    }

    public function test_return_error_message_mentions_the_configured_window(): void
    {
        $this->actingAsAdmin()->patchJson(self::ADMIN . '/orders.return_window_days', ['value' => 7])->assertOk();
        [$order, $user] = $this->deliveredOrderFor();
        Carbon::setTestNow(now()->addDays(8));

        $this->assertStringContainsString('7-day', $this->requestReturn($user, $order->id)->assertStatus(422)->json('message'));
    }

    public function test_only_the_owner_can_request_a_return(): void
    {
        [$order] = $this->deliveredOrderFor();
        [$stranger] = $this->shopper();

        $this->requestReturn($stranger, $order->id)->assertNotFound();
        $this->assertSame('delivered', $this->statusOf($order->id));
    }

    // --------------------------------------------------------- safe fallbacks

    public function test_missing_rows_fall_back_to_the_config_defaults(): void
    {
        BusinessSetting::query()->delete();
        $this->svc()->flush();
        config(['vistaro.shipping.flat_fee' => 120, 'vistaro.shipping.free_above' => 3000, 'vistaro.orders.return_window_days' => 21]);

        $this->assertSame(120, $this->svc()->shippingFlatFee());
        $this->assertSame(3000, $this->svc()->freeShippingThreshold());
        $this->assertSame(21, $this->svc()->returnWindowDays());
        $this->assertSame('120.00', $this->cartTotals(1000)['shipping_fee']);
    }

    public function test_invalid_config_and_missing_rows_fall_back_to_the_hard_defaults(): void
    {
        BusinessSetting::query()->delete();
        $this->svc()->flush();
        config(['vistaro.shipping.flat_fee' => 'abc', 'vistaro.shipping.free_above' => -1, 'vistaro.orders.return_window_days' => 99999]);

        $this->assertSame(99, $this->svc()->shippingFlatFee());
        $this->assertSame(1999, $this->svc()->freeShippingThreshold());
        $this->assertSame(14, $this->svc()->returnWindowDays());
    }

    public function test_corrupt_stored_values_never_break_pricing_and_use_the_safe_default(): void
    {
        $this->rawSet('shipping.flat_fee', ['value' => 'not-a-number']);
        $this->rawSet('shipping.free_above', ['value' => '-50']);                                         // out of range
        $this->rawSet('orders.return_window_days', ['value' => '9999']);                                  // out of range

        $this->assertSame(99, $this->svc()->shippingFlatFee());
        $this->assertSame(1999, $this->svc()->freeShippingThreshold());
        $this->assertSame(14, $this->svc()->returnWindowDays());

        $t = $this->cartTotals(1000);                                                                     // checkout still works
        $this->assertSame('99.00', $t['shipping_fee']);
    }

    public function test_a_row_with_the_wrong_type_is_ignored_for_business_rules(): void
    {
        $this->rawSet('shipping.flat_fee', ['type' => 'string', 'value' => '500']);

        $this->assertSame(99, $this->svc()->shippingFlatFee());
    }

    public function test_corrupt_generic_values_return_the_caller_default_and_are_hidden_from_nothing_else(): void
    {
        $this->create(['key' => 'store.flag', 'type' => 'boolean', 'value' => true])->assertCreated();
        $this->rawSet('store.flag', ['value' => 'garbage']);

        $this->assertSame('fallback', $this->svc()->get('store.flag', 'fallback'));
        $this->assertSame('fallback', $this->svc()->get('store.never_created', 'fallback'));
        $this->assertFalse($this->svc()->has('store.never_created'));
        $this->guest()->getJson('/api/v1/settings')->assertOk();                                          // a corrupt row must not 500 the public list
    }

    public function test_unknown_business_rule_key_is_a_programming_error_not_silent(): void
    {
        $this->expectException(\LogicException::class);
        $method = new \ReflectionMethod(BusinessSettingsService::class, 'knownInteger');
        $method->setAccessible(true);
        $method->invoke($this->svc(), 'shipping.does_not_exist');
    }

    // ------------------------------------------------- migration / seeder / DI

    public function test_the_settings_table_has_the_expected_shape_and_unique_key(): void
    {
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('business_settings');
        foreach (['id', 'key', 'value', 'type', 'group', 'description', 'is_public', 'created_at', 'updated_at'] as $c) {
            $this->assertContains($c, $cols);
        }
        $unique = collect(\Illuminate\Support\Facades\Schema::getIndexes('business_settings'))->contains(fn ($i) => $i['unique'] && $i['columns'] === ['key']);
        $this->assertTrue($unique, 'business_settings.key must be unique');
    }

    public function test_seeded_defaults_match_the_pre_existing_behaviour(): void
    {
        $this->assertSame([99, 1999, 14], [$this->svc()->shippingFlatFee(), $this->svc()->freeShippingThreshold(), $this->svc()->returnWindowDays()]);
        foreach (['shipping.flat_fee', 'shipping.free_above', 'orders.return_window_days'] as $k) {
            $this->assertTrue((bool) BusinessSetting::where('key', $k)->value('is_public'));
        }
    }

    public function test_the_service_is_resolved_from_the_container_and_shared_within_a_request_scope(): void
    {
        $this->assertSame(app(BusinessSettingsService::class), app(BusinessSettingsService::class));
    }
}
