<?php

namespace Tests\Feature\Catalog;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\InventoryMovement;
use App\Models\OfflineStock;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class AdminInventoryTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private function base(Product $p, string $suffix = ''): string
    {
        return "/api/v1/admin/products/{$p->id}{$suffix}";
    }

    private function online(Product $p, string $size): int
    {
        return ProductSize::where(['product_id' => $p->id, 'size' => $size])->value('quantity');
    }

    private function shop(Product $p, string $size): int
    {
        return OfflineStock::where(['product_id' => $p->id, 'size' => $size])->value('quantity');
    }

    // ------------------------------------------------------------- bulk sizes

    public function test_sizes_are_created_in_bulk_without_duplicates_and_all_eight_are_supported(): void
    {
        $p = Product::factory()->create(['sku' => 'VS-BULK-1']);
        $rows = array_map(fn ($s) => ['size' => $s, 'quantity' => 4], ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL']);

        $this->actingAsAdmin()->putJson($this->base($p, '/sizes'), ['sizes' => $rows])->assertOk()
            ->assertJsonPath('data.stock.online.total', 32)->assertJsonCount(8, 'data.stock.online.sizes');

        $this->assertSame(8, ProductSize::where('product_id', $p->id)->count());
        $this->assertSame(8, OfflineStock::where('product_id', $p->id)->count());
        $this->assertSame('VS-BULK-1-3XL', ProductSize::where(['product_id' => $p->id, 'size' => '3XL'])->value('sku'));

        // repeating the same call updates, it never duplicates
        $this->actingAsAdmin()->putJson($this->base($p, '/sizes'), ['sizes' => [['size' => 'M', 'quantity' => 9], ['size' => 'L', 'offline_quantity' => 3]]])->assertOk();
        $this->assertSame(8, ProductSize::where('product_id', $p->id)->count());
        $this->assertSame(9, $this->online($p, 'M'));
        $this->assertSame(4, $this->online($p, 'L'), 'a size row without `quantity` keeps its online stock');
        $this->assertSame(3, $this->shop($p, 'L'));

        $this->assertSame(8, InventoryMovement::where('type', 'initial')->count());
        $this->assertSame(2, InventoryMovement::where('type', 'adjustment')->count());
    }

    public function test_bulk_size_validation(): void
    {
        $p = Product::factory()->create();

        foreach ([
            ['sizes' => []], [], ['sizes' => [['size' => '5XL']]], ['sizes' => [['size' => 'm']]], ['sizes' => [['size' => 'M'], ['size' => 'M']]],
            ['sizes' => [['size' => 'M', 'quantity' => -1]]], ['sizes' => [['size' => 'M', 'quantity' => 1.5]]], ['sizes' => [['size' => 'M', 'quantity' => 1000001]]],
            ['sizes' => [['quantity' => 3]]],
        ] as $bad) {
            $this->actingAsAdmin()->putJson($this->base($p, '/sizes'), $bad)->assertStatus(422);
        }
        $this->assertSame(0, ProductSize::count());
    }

    public function test_removing_a_size_requires_zero_stock_everywhere(): void
    {
        $p = $this->makeProduct(['M' => 0, 'L' => 5]);
        OfflineStock::where(['product_id' => $p->id, 'size' => 'M'])->update(['quantity' => 2]);

        $this->actingAsAdmin()->deleteJson($this->base($p, '/sizes/L'))->assertStatus(409);
        $this->actingAsAdmin()->deleteJson($this->base($p, '/sizes/M'))->assertStatus(409);        // stock in the shop
        $this->actingAsAdmin()->deleteJson($this->base($p, '/sizes/ZZ'))->assertNotFound();
        OfflineStock::where(['product_id' => $p->id, 'size' => 'M'])->update(['quantity' => 0]);
        $this->actingAsAdmin()->deleteJson($this->base($p, '/sizes/M'))->assertOk();

        $this->assertSame(['L'], ProductSize::where('product_id', $p->id)->get()->map(fn ($s) => $s->size->value)->all());
        $this->assertSame(1, OfflineStock::where('product_id', $p->id)->count());
    }

    // ---------------------------------------------------------- set / adjust

    public function test_set_stock_applies_absolute_quantities_and_records_who_and_why(): void
    {
        $p = $this->makeProduct(['S' => 5, 'M' => 8, 'L' => 0]);
        $admin = Admin::factory()->create(['role' => AdminRole::Admin]);
        $token = $admin->createToken('t', ['admin'], now()->addHour())->plainTextToken;

        $this->guest()->withToken($token)->putJson($this->base($p, '/stock'), ['location' => 'online', 'sizes' => ['S' => 5, 'M' => 3, 'L' => 40], 'note' => 'stock take'])
            ->assertOk()->assertJsonPath('data.stock.online.total', 48);

        $this->assertSame([5, 3, 40], [$this->online($p, 'S'), $this->online($p, 'M'), $this->online($p, 'L')]);
        $moves = InventoryMovement::orderBy('id')->get();
        $this->assertCount(2, $moves, 'S was unchanged so it produced no movement');
        $this->assertSame([-5, 40], $moves->pluck('quantity_change')->all());
        $this->assertSame(['M', 'L'], $moves->pluck('size')->all());
        $this->assertSame([3, 40], $moves->pluck('quantity_after')->all());
        $this->assertSame(['admin', 'admin'], $moves->pluck('actor_type')->all());
        $this->assertSame([$admin->id, $admin->id], $moves->pluck('actor_id')->all());
        $this->assertSame(['stock take', 'stock take'], $moves->pluck('note')->all());
        $this->assertSame(['online', 'online'], $moves->pluck('location')->map->value->all());
    }

    public function test_set_stock_is_all_or_nothing_and_rejects_unknown_or_invalid_values(): void
    {
        $p = $this->makeProduct(['S' => 5, 'M' => 8]);
        $url = $this->base($p, '/stock');

        // one unknown size => nothing at all is applied
        $this->actingAsAdmin()->putJson($url, ['location' => 'online', 'sizes' => ['S' => 50, 'XL' => 3]])->assertStatus(422)->assertJsonStructure(['errors' => ['sizes.XL']]);
        $this->assertSame([5, 8], [$this->online($p, 'S'), $this->online($p, 'M')]);

        foreach ([
            ['location' => 'online', 'sizes' => ['S' => -1]], ['location' => 'online', 'sizes' => ['S' => 'abc']], ['location' => 'online', 'sizes' => ['S' => 1.5]],
            ['location' => 'online', 'sizes' => ['S' => 1000001]], ['location' => 'online', 'sizes' => ['5XL' => 1]], ['location' => 'online', 'sizes' => []],
            ['location' => 'warehouse', 'sizes' => ['S' => 1]], ['sizes' => ['S' => 1]], ['location' => 'online'],
        ] as $bad) {
            $this->actingAsAdmin()->putJson($url, $bad)->assertStatus(422);
        }
        $this->assertSame([5, 8], [$this->online($p, 'S'), $this->online($p, 'M')]);
        $this->assertSame(0, InventoryMovement::count());

        $this->actingAsAdmin()->putJson($url, ['location' => 'online', 'sizes' => ['S' => '0']])->assertOk();          // numeric string is fine
        $this->assertSame(0, $this->online($p, 'S'));
        $this->actingAsAdmin()->putJson('/api/v1/admin/products/99999/stock', ['location' => 'online', 'sizes' => ['S' => 1]])->assertNotFound();
    }

    public function test_adjust_changes_stock_relatively_and_can_never_go_negative(): void
    {
        $p = $this->makeProduct(['M' => 4]);
        $url = $this->base($p, '/stock/adjust');

        $this->actingAsAdmin()->postJson($url, ['location' => 'online', 'size' => 'M', 'delta' => 6, 'note' => 'delivery'])->assertOk()->assertJsonPath('data.stock.online.total', 10);
        $this->actingAsAdmin()->postJson($url, ['location' => 'online', 'size' => 'M', 'delta' => -10])->assertOk()->assertJsonPath('data.stock.online.total', 0);

        $before = InventoryMovement::count();
        $this->actingAsAdmin()->postJson($url, ['location' => 'online', 'size' => 'M', 'delta' => -1])->assertStatus(409)->assertJsonPath('success', false);
        $this->assertSame(0, $this->online($p, 'M'), 'refused: no negative stock');
        $this->assertSame($before, InventoryMovement::count(), 'and no movement is recorded for a refused change');

        foreach ([['delta' => 0], ['delta' => 100001], ['delta' => 'x'], ['size' => 'XXXL'], ['location' => 'x']] as $bad) {
            $this->actingAsAdmin()->postJson($url, $bad + ['location' => 'online', 'size' => 'M', 'delta' => 1])->assertStatus(422);
        }
        $this->actingAsAdmin()->postJson($url, ['location' => 'online', 'size' => 'XL', 'delta' => 1])->assertStatus(422);   // size not defined for the product
    }

    public function test_online_and_offline_stock_are_independent(): void
    {
        $p = $this->makeProduct(['M' => 10]);

        $this->actingAsAdmin()->putJson($this->base($p, '/stock'), ['location' => 'offline', 'sizes' => ['M' => 7]])->assertOk()
            ->assertJsonPath('data.stock.online.total', 10)->assertJsonPath('data.stock.offline.total', 7);
        $this->actingAsAdmin()->postJson($this->base($p, '/stock/adjust'), ['location' => 'offline', 'size' => 'M', 'delta' => -3])->assertOk();
        $this->actingAsAdmin()->postJson($this->base($p, '/stock/adjust'), ['location' => 'offline', 'size' => 'M', 'delta' => -5])->assertStatus(409);

        $this->assertSame([10, 4], [$this->online($p, 'M'), $this->shop($p, 'M')]);
        $this->assertSame(['offline', 'offline'], InventoryMovement::pluck('location')->map->value->all());

        // a shop sale never changes what the website sells, and vice versa
        $this->actingAsAdmin()->postJson($this->base($p, '/stock/adjust'), ['location' => 'online', 'size' => 'M', 'delta' => -10])->assertOk();
        $this->assertSame(4, $this->shop($p, 'M'));
        $this->guest()->getJson("/api/v1/products/{$p->slug}")->assertJsonPath('data.in_stock', false);
    }

    public function test_staff_may_change_stock_and_customers_may_not(): void
    {
        $p = $this->makeProduct(['M' => 4]);
        $body = ['location' => 'online', 'size' => 'M', 'delta' => 1];

        $this->actingAsAdmin(AdminRole::Staff)->postJson($this->base($p, '/stock/adjust'), $body)->assertOk();
        $this->actingAsCustomer()->postJson($this->base($p, '/stock/adjust'), $body)->assertUnauthorized();
        $this->guest()->putJson($this->base($p, '/stock'), ['location' => 'online', 'sizes' => ['M' => 999]])->assertUnauthorized();
        $this->assertSame(5, $this->online($p, 'M'));
    }

    // ---------------------------------------------------------- thresholds

    public function test_admin_stock_status_rules(): void
    {
        $p = $this->makeProduct(['XS' => 0, 'S' => 3, 'M' => 4, 'L' => 5, 'XL' => 20]);

        $r = $this->actingAsAdmin()->getJson($this->base($p))->assertOk();
        $online = $r->json('data.stock.online');

        $this->assertSame(32, $online['total']);
        $this->assertSame('low', $online['status']);
        $this->assertTrue($online['low_stock']);
        $this->assertFalse($online['out_of_stock']);
        $this->assertSame(['out', 'low', 'low', 'ok', 'ok'], collect($online['sizes'])->pluck('status')->all(), '< 5 is low, 0 is out, 5 is fine');
        $this->assertSame([0, 3, 4, 5, 20], collect($online['sizes'])->pluck('quantity')->all(), 'admin sees exact numbers');

        $ok = $this->makeProduct(['S' => 5, 'M' => 50]);
        $this->assertSame('ok', $this->actingAsAdmin()->getJson($this->base($ok))->json('data.stock.online.status'));
        $out = $this->makeProduct(['S' => 0, 'M' => 0]);
        $o = $this->actingAsAdmin()->getJson($this->base($out))->json('data.stock.online');
        $this->assertSame(['out', true, 0], [$o['status'], $o['out_of_stock'], $o['total']]);
        $this->assertFalse($o['low_stock'], 'out of stock is reported as out, not as low');
    }

    public function test_storefront_follows_admin_stock_changes_and_never_leaks_exact_large_quantities(): void
    {
        $p = $this->makeProduct(['M' => 50]);
        $show = fn () => collect($this->guest()->getJson("/api/v1/products/{$p->slug}")->json('data.sizes'))->firstWhere('size', 'M');

        $this->assertSame([true, null, null], [$show()['available'], $show()['left'], $show()['label']]);

        $this->actingAsAdmin()->putJson($this->base($p, '/stock'), ['location' => 'online', 'sizes' => ['M' => 8]])->assertOk();
        $this->assertSame([true, 8, '8 left'], [$show()['available'], $show()['left'], $show()['label']]);

        $this->actingAsAdmin()->putJson($this->base($p, '/stock'), ['location' => 'online', 'sizes' => ['M' => 0]])->assertOk();
        $this->assertSame([false, null, null], [$show()['available'], $show()['left'], $show()['label']]);
    }

    // ------------------------------------------------- inventory & low stock

    public function test_inventory_table_filters_sorts_and_switches_location(): void
    {
        $a = $this->makeProduct(['S' => 30, 'M' => 30], ['name' => 'Alpha', 'sku' => 'INV-A']);
        $b = $this->makeProduct(['S' => 2, 'M' => 40], ['name' => 'Bravo', 'sku' => 'INV-B']);
        $c = $this->makeProduct(['S' => 0, 'M' => 0], ['name' => 'Charlie', 'sku' => 'INV-C']);
        $archived = $this->makeProduct(['S' => 1], ['name' => 'Gone', 'sku' => 'INV-D', 'status' => 'archived']);
        OfflineStock::where(['product_id' => $c->id, 'size' => 'M'])->update(['quantity' => 15]);
        OfflineStock::where(['product_id' => $c->id, 'size' => 'S'])->update(['quantity' => 15]);

        $get = fn (string $qs) => $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/inventory' . $qs);
        $names = fn ($r) => collect($r->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names($get('')), 'archived products are not stocked');
        $this->assertSame(['Alpha'], $names($get('?stock_status=in')));
        $this->assertSame(['Bravo'], $names($get('?stock_status=low')));
        $this->assertSame(['Charlie'], $names($get('?stock_status=out')));
        $this->assertSame(['Bravo'], $names($get('?q=INV-B')));
        $order = fn (string $dir) => collect($get("?sort=stock&direction={$dir}")->json('data'))->pluck('name')->all();
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $order('desc'), 'totals 60 / 42 / 0');

        // location=offline reads the shop table instead
        $shop = $get('?location=offline');
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names($shop));
        $charlie = collect($shop->json('data'))->firstWhere('name', 'Charlie')['stock'];
        $this->assertSame(['offline'], array_keys($charlie));
        $this->assertSame(30, $charlie['offline']['total']);
        $this->assertSame(['Charlie'], $names($get('?location=offline&stock_status=in')), 'only Charlie has shop stock >= 5 in every size');
        $this->assertSame(['Alpha', 'Bravo'], $names($get('?location=offline&stock_status=out')));

        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $order('asc'));
        $this->actingAsAdmin()->getJson('/api/v1/admin/inventory?location=warehouse')->assertStatus(422);
        $this->actingAsAdmin()->getJson('/api/v1/admin/inventory?per_page=101')->assertStatus(422);
    }

    public function test_inventory_pagination_defaults(): void
    {
        foreach (range(1, 23) as $i) {
            $this->makeProduct(['M' => 5]);
        }

        $this->actingAsAdmin()->getJson('/api/v1/admin/inventory')->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 3);
        $this->actingAsAdmin()->getJson('/api/v1/admin/inventory?per_page=20')->assertJsonCount(20, 'data');
    }

    public function test_low_stock_report_lists_every_size_under_five_including_zero(): void
    {
        $a = $this->makeProduct(['S' => 0, 'M' => 3, 'L' => 5, 'XL' => 4], ['name' => 'Alpha']);
        $b = $this->makeProduct(['M' => 1, 'L' => 50], ['name' => 'Bravo']);
        $this->makeProduct(['M' => 2], ['name' => 'Archived', 'status' => 'archived']);

        $r = $this->actingAsAdmin(AdminRole::Staff)->getJson('/api/v1/admin/inventory/low-stock')->assertOk();
        $rows = collect($r->json('data'));

        $this->assertSame(['Alpha:S:0:out', 'Bravo:M:1:low', 'Alpha:M:3:low', 'Alpha:XL:4:low'], $rows->map(fn ($x) => "{$x['product_name']}:{$x['size']}:{$x['quantity']}:{$x['status']}")->all(), 'emptiest first; 5 is not low; archived excluded');
        $this->assertSame(4, $r->json('meta.total'));

        OfflineStock::where(['product_id' => $a->id, 'size' => 'M'])->update(['quantity' => 2]);
        $shop = $this->actingAsAdmin()->getJson('/api/v1/admin/inventory/low-stock?location=offline')->json('data');
        $this->assertTrue(collect($shop)->contains(fn ($x) => $x['product_name'] === 'Alpha' && $x['size'] === 'M' && $x['quantity'] === 2));
    }

    public function test_low_stock_report_is_paginated_with_a_constant_number_of_queries(): void
    {
        foreach (range(1, 15) as $i) {
            $this->makeProduct(['S' => 1, 'M' => 0]);
        }

        $r = $this->actingAsAdmin()->getJson('/api/v1/admin/inventory/low-stock')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 30)->assertJsonPath('meta.last_page', 3);
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->actingAsAdmin()->getJson('/api/v1/admin/inventory/low-stock?per_page=20')->assertJsonCount(20, 'data');
        $this->assertLessThanOrEqual(8, count(\DB::getQueryLog()), 'product names are eager loaded, not queried per row');
    }
}
