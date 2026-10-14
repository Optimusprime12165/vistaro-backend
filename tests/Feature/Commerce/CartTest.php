<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class CartTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function me(): static
    {
        return $this->actingAsCustomer($this->user);
    }

    private function add(Product $p, string $size, int $qty = 1, ?int $colorId = null, array $extra = [])
    {
        return $this->me()->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'size' => $size, 'quantity' => $qty, 'color_id' => $colorId] + $extra);
    }

    private function cart()
    {
        return $this->me()->getJson('/api/v1/cart')->assertOk();
    }

    private function priced(string $price, string $mrp, array $stock = ['M' => 20, 'L' => 20], array $colors = []): Product
    {
        return $this->makeProduct($stock, ['price' => $price, 'mrp' => $mrp], $colors);
    }

    public function test_the_cart_requires_a_customer_token(): void
    {
        foreach ([['get', '/api/v1/cart'], ['delete', '/api/v1/cart'], ['post', '/api/v1/cart/items'], ['patch', '/api/v1/cart/items/1'], ['delete', '/api/v1/cart/items/1']] as [$m, $u]) {
            $this->guest()->json($m, $u, [])->assertUnauthorized();
            $this->actingAsAdmin()->json($m, $u, [])->assertUnauthorized();
        }
    }

    public function test_an_empty_cart(): void
    {
        $r = $this->cart();

        $this->assertSame([], $r->json('data.items'));
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', 0, false], [
            $r->json('data.totals.subtotal'), $r->json('data.totals.shipping_fee'), $r->json('data.totals.total'),
            $r->json('data.totals.amount_for_free_shipping'), $r->json('data.totals.item_count'), $r->json('data.can_checkout'),
        ]);
    }

    public function test_prices_totals_discount_and_shipping_are_computed_on_the_server(): void
    {
        $a = $this->priced('1299.50', '1999.00');
        $b = $this->priced('500.00', '700.00');

        $this->add($a, 'M', 3)->assertCreated();
        $r = $this->add($b, 'L', 2)->assertCreated();

        // 3 x 1299.50 + 2 x 500.00 = 4898.50 (exact, no float rounding); MRP 3 x 1999 + 2 x 700 = 7397
        $t = $r->json('data.totals');
        $this->assertSame(['4898.50', '7397.00', '2498.50', '0.00', '4898.50', 5], [$t['subtotal'], $t['mrp_total'], $t['discount_total'], $t['shipping_fee'], $t['total'], $t['item_count']]);
        $this->assertSame(['3898.50', '1000.00'], collect($r->json('data.items'))->pluck('line_subtotal')->all());
        $this->assertSame(['1299.50', '500.00'], collect($r->json('data.items'))->pluck('unit_price')->all());
        $this->assertTrue($r->json('data.can_checkout'));
    }

    public function test_client_supplied_prices_and_totals_are_ignored(): void
    {
        $p = $this->priced('1000.00', '1500.00');

        $r = $this->add($p, 'M', 1, null, ['price' => 1, 'unit_price' => 1, 'total' => 1, 'subtotal' => 1, 'discount' => 99, 'mrp' => 1, 'shipping_fee' => 0])->assertCreated();

        $this->assertSame('1000.00', $r->json('data.items.0.unit_price'));
        $this->assertSame('1099.00', $r->json('data.totals.total'));          // 1000 + 99 shipping
        $this->assertSame(['product_size_id', 'product_color_id', 'quantity'], array_values(array_diff(array_keys(CartItem::first()->getAttributes()), ['id', 'cart_id', 'created_at', 'updated_at'])), 'no price columns exist or are stored');
    }

    public function test_shipping_rule_threshold(): void
    {
        $under = $this->priced('1998.00', '2500.00', ['M' => 5]);
        $exact = $this->priced('1999.00', '2500.00', ['M' => 5]);

        $r = $this->add($under, 'M')->assertCreated();
        $this->assertSame(['99.00', '2097.00', '1.00'], [$r->json('data.totals.shipping_fee'), $r->json('data.totals.total'), $r->json('data.totals.amount_for_free_shipping')]);

        $this->me()->deleteJson('/api/v1/cart')->assertOk();
        $r = $this->add($exact, 'M')->assertCreated();
        $this->assertSame(['0.00', '1999.00', '0.00'], [$r->json('data.totals.shipping_fee'), $r->json('data.totals.total'), $r->json('data.totals.amount_for_free_shipping')], 'free at exactly 1999');
    }

    public function test_price_changes_show_up_immediately_because_nothing_is_stored(): void
    {
        $p = $this->priced('1000.00', '1500.00');
        $this->add($p, 'M', 2)->assertCreated();

        $p->update(['price' => 800]);

        $r = $this->cart();
        $this->assertSame(['800.00', '1600.00'], [$r->json('data.items.0.unit_price'), $r->json('data.totals.subtotal')]);
    }

    public function test_lines_carry_size_colour_image_and_stock_hints(): void
    {
        $p = $this->priced('999.00', '1499.00', ['M' => 8, 'L' => 40], [['Black', '#111111'], ['Navy', '#1F2A44']]);
        ProductImage::factory()->for($p)->create(['disk' => 'external', 'path' => 'https://img.example/p.jpg', 'is_primary' => true]);
        $navy = $p->colors->firstWhere('name', 'Navy');

        $this->add($p, 'M', 1, $navy->id)->assertCreated();
        $this->add($p, 'L', 1, $navy->id)->assertCreated();

        $items = collect($this->cart()->json('data.items'));
        $m = $items->firstWhere('size', 'M');
        $this->assertSame(['Navy', '#1F2A44'], [$m['color']['name'], $m['color']['hex']]);
        $this->assertSame('https://img.example/p.jpg', $m['image']);
        $this->assertSame([8, true, null], [$m['stock_left'], $m['available'], $m['issue']]);
        $this->assertNull($items->firstWhere('size', 'L')['stock_left'], 'exact quantity above 10 is not revealed');
        $this->assertStringNotContainsString('"quantity":40', json_encode($items->all()));
    }

    // ---------------------------------------------------------- add rules

    public function test_variant_rules_on_add(): void
    {
        $withColors = $this->priced('999.00', '1499.00', ['M' => 5], [['Black', '#111111']]);
        $plain = $this->priced('999.00', '1499.00', ['M' => 5]);
        $foreignColor = $this->priced('999.00', '1499.00', ['M' => 5], [['Red', '#FF0000']])->colors->first();

        $this->add($withColors, 'M')->assertStatus(422)->assertJsonStructure(['errors' => ['color_id']]);                       // colour required
        $this->add($withColors, 'M', 1, $foreignColor->id)->assertStatus(422)->assertJsonStructure(['errors' => ['color_id']]); // someone else's colour
        $this->add($plain, 'M', 1, $withColors->colors->first()->id)->assertStatus(422);                                         // colour on a colourless product
        $this->add($plain, 'XL')->assertStatus(422)->assertJsonStructure(['errors' => ['size']]);                                // size the product does not have
        $this->add($plain, '5XL')->assertStatus(422);
        $this->add($plain, 'M', 0)->assertStatus(422);
        $this->add($plain, 'M', 11)->assertStatus(422);
        $this->add($plain, 'M', -2)->assertStatus(422);
        $this->me()->postJson('/api/v1/cart/items', ['size' => 'M', 'quantity' => 1])->assertStatus(422);
        $this->me()->postJson('/api/v1/cart/items', ['product_id' => 999999, 'size' => 'M', 'quantity' => 1])->assertStatus(422)->assertJsonStructure(['errors' => ['product_id']]);

        $this->assertSame(0, CartItem::count());
        $this->add($withColors, 'M', 1, $withColors->colors->first()->id)->assertCreated();
    }

    public function test_unavailable_products_cannot_be_added(): void
    {
        $cases = [
            'draft' => ['status' => 'draft'], 'archived' => ['status' => 'archived'], 'switched off' => ['is_available' => false],
        ];
        foreach ($cases as $label => $attrs) {
            $p = $this->makeProduct(['M' => 5], $attrs);
            $this->add($p, 'M')->assertStatus(422, $label)->assertJsonStructure(['errors' => ['product_id']]);
        }

        $inactiveCategory = $this->makeProduct(['M' => 5]);
        Category::whereKey($inactiveCategory->category_id)->update(['is_active' => false]);
        $this->add($inactiveCategory, 'M')->assertStatus(422);

        $inactiveSub = $this->makeProduct(['M' => 5]);
        $inactiveSub->subCategory->update(['is_active' => false]);
        $this->add($inactiveSub, 'M')->assertStatus(422);

        $this->assertSame(0, CartItem::count());
    }

    public function test_stock_is_validated_on_add(): void
    {
        $p = $this->makeProduct(['M' => 3, 'L' => 0]);

        $this->add($p, 'M', 4)->assertStatus(409)->assertJsonPath('errors.quantity.0', 'Only 3 left.');
        $this->add($p, 'L', 1)->assertStatus(409)->assertJsonPath('errors.quantity.0', 'Out of stock.');
        $this->add($p, 'M', 3)->assertCreated();
        $this->add($p, 'M', 1)->assertStatus(409);                       // 3 + 1 > 3 once merged
        $this->assertSame(3, CartItem::first()->quantity);
    }

    public function test_duplicate_lines_are_merged_and_capped_at_ten(): void
    {
        $p = $this->makeProduct(['M' => 50, 'L' => 50], [], [['Black', '#111111'], ['Navy', '#1F2A44']]);
        [$black, $navy] = $p->colors->all();

        $this->add($p, 'M', 4, $black->id)->assertCreated();
        $this->add($p, 'M', 5, $black->id)->assertCreated()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 9);
        $this->add($p, 'M', 2, $black->id)->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);   // 9 + 2 > 10
        $this->add($p, 'M', 1, $black->id)->assertCreated()->assertJsonPath('data.items.0.quantity', 10);

        $r = $this->add($p, 'M', 1, $navy->id)->assertCreated();                                                 // other colour = other line
        $this->add($p, 'L', 1, $black->id)->assertCreated();                                                     // other size = other line
        $this->assertCount(3, $this->cart()->json('data.items'));
        $this->assertSame(1, Cart::count(), 'one cart per customer');
    }

    public function test_colourless_products_merge_too(): void
    {
        $p = $this->makeProduct(['M' => 50]);

        $this->add($p, 'M', 2)->assertCreated();
        $this->add($p, 'M', 3)->assertCreated()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 5);
        $this->assertSame(1, CartItem::count(), 'NULL colours are merged by the service although SQL unique keys ignore NULLs');
    }

    // ------------------------------------------------------ update / remove

    public function test_quantity_size_and_colour_can_be_changed(): void
    {
        $p = $this->makeProduct(['M' => 5, 'L' => 5], [], [['Black', '#111111'], ['Navy', '#1F2A44']]);
        [$black, $navy] = $p->colors->all();
        $id = $this->add($p, 'M', 2, $black->id)->json('data.items.0.id');

        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['quantity' => 4])->assertOk()->assertJsonPath('data.items.0.quantity', 4);
        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['size' => 'L'])->assertOk()->assertJsonPath('data.items.0.size', 'L')->assertJsonPath('data.items.0.quantity', 4);
        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['color_id' => $navy->id, 'quantity' => 1])->assertOk()->assertJsonPath('data.items.0.color.name', 'Navy');

        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['quantity' => 6])->assertStatus(409);       // only 5 in stock
        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['quantity' => 11])->assertStatus(422);
        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['quantity' => 0])->assertStatus(422);
        $this->me()->patchJson("/api/v1/cart/items/{$id}", [])->assertStatus(422);
        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['size' => 'XL'])->assertStatus(422);
        $this->me()->patchJson("/api/v1/cart/items/{$id}", ['color_id' => 999999])->assertStatus(422);
        $this->me()->patchJson('/api/v1/cart/items/999999', ['quantity' => 1])->assertNotFound();
        $this->assertSame(1, CartItem::first()->quantity);
    }

    public function test_changing_a_line_onto_an_existing_one_merges_them(): void
    {
        $p = $this->makeProduct(['M' => 50, 'L' => 50]);
        $m = $this->add($p, 'M', 3)->json('data.items.0.id');
        $this->add($p, 'L', 4)->assertCreated();

        $r = $this->me()->patchJson("/api/v1/cart/items/{$m}", ['size' => 'L'])->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame([7, 'L'], [$r->json('data.items.0.quantity'), $r->json('data.items.0.size')]);

        $over = $this->add($p, 'M', 5)->json('data.items.1.id');
        $this->me()->patchJson("/api/v1/cart/items/{$over}", ['size' => 'L'])->assertStatus(422);       // 7 + 5 > 10
        $this->assertCount(2, $this->cart()->json('data.items'));
    }

    public function test_remove_and_clear(): void
    {
        $p = $this->makeProduct(['M' => 9, 'L' => 9]);
        $a = $this->add($p, 'M')->json('data.items.0.id');
        $this->add($p, 'L')->assertCreated();

        $this->me()->deleteJson("/api/v1/cart/items/{$a}")->assertOk()->assertJsonCount(1, 'data.items');
        $this->me()->deleteJson("/api/v1/cart/items/{$a}")->assertNotFound();
        $this->me()->deleteJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.totals.total', '0.00');
        $this->me()->deleteJson('/api/v1/cart')->assertOk();                                               // clearing an empty cart is fine
        $this->assertSame(0, CartItem::count());
    }

    public function test_customers_cannot_touch_each_others_carts(): void
    {
        $p = $this->makeProduct(['M' => 9]);
        $mine = $this->add($p, 'M', 2)->json('data.items.0.id');

        $other = User::factory()->create();
        $this->actingAsCustomer($other)->getJson('/api/v1/cart')->assertJsonCount(0, 'data.items');
        $this->actingAsCustomer($other)->patchJson("/api/v1/cart/items/{$mine}", ['quantity' => 5])->assertNotFound();
        $this->actingAsCustomer($other)->deleteJson("/api/v1/cart/items/{$mine}")->assertNotFound();
        $this->actingAsCustomer($other)->deleteJson('/api/v1/cart')->assertOk();

        $this->assertSame(2, CartItem::findOrFail($mine)->quantity, 'my line is untouched');
        $this->assertCount(1, $this->cart()->json('data.items'));
    }

    // ------------------------------------------- items that changed after adding

    public function test_lines_that_became_unavailable_are_flagged_not_silently_dropped(): void
    {
        $gone = $this->priced('1000.00', '1200.00', ['M' => 5]);
        $short = $this->priced('1000.00', '1200.00', ['M' => 5]);
        $empty = $this->priced('1000.00', '1200.00', ['M' => 5]);
        $fine = $this->priced('1000.00', '1200.00', ['M' => 5]);
        foreach ([$gone, $short, $empty, $fine] as $p) {
            $this->add($p, 'M', 2)->assertCreated();
        }

        $gone->update(['status' => 'draft']);
        ProductSize::where('product_id', $short->id)->update(['quantity' => 1]);
        ProductSize::where('product_id', $empty->id)->update(['quantity' => 0]);

        $r = $this->cart();
        $issues = collect($r->json('data.items'))->pluck('issue', 'product.id')->all();
        $this->assertSame([$gone->id => 'product_unavailable', $short->id => 'insufficient_stock', $empty->id => 'out_of_stock', $fine->id => null], $issues);
        $this->assertFalse($r->json('data.can_checkout'));
        $this->assertCount(3, $r->json('data.issues'));
        $this->assertSame('Only 1 left in size M.', collect($r->json('data.items'))->firstWhere('product.id', $short->id)['issue_message']);
        $this->assertSame(8, $r->json('data.totals.item_count'), 'flagged lines are still priced');

        // removing the offenders makes the cart checkout-ready again
        foreach ($r->json('data.items') as $item) {
            if ($item['issue']) {
                $this->me()->deleteJson("/api/v1/cart/items/{$item['id']}")->assertOk();
            }
        }
        $this->assertTrue($this->cart()->json('data.can_checkout'));
    }

    public function test_a_deleted_variant_removes_its_cart_line_safely(): void
    {
        $p = $this->makeProduct(['M' => 0, 'L' => 5]);
        $this->add($p, 'L', 1)->assertCreated();
        ProductSize::where(['product_id' => $p->id, 'size' => 'L'])->first()->delete();

        $this->cart()->assertJsonCount(0, 'data.items');
    }

    public function test_colour_lines_of_one_size_share_the_same_stock(): void
    {
        $p = $this->makeProduct(['M' => 5], [], [['Black', '#111111'], ['Navy', '#1F2A44']]);
        [$black, $navy] = $p->colors->all();

        $this->add($p, 'M', 3, $black->id)->assertCreated();
        $this->add($p, 'M', 3, $navy->id)->assertStatus(409)->assertJsonPath('errors.quantity.0', 'Only 2 left.');   // 3 + 3 > 5
        $this->add($p, 'M', 2, $navy->id)->assertCreated();
        $this->add($p, 'M', 1, $navy->id)->assertStatus(409);

        // the combined demand is also what the cart view checks
        $this->assertTrue($this->cart()->json('data.can_checkout'));
        ProductSize::where('product_id', $p->id)->update(['quantity' => 4]);                          // 3 + 2 = 5 now exceeds 4
        $r = $this->cart();
        $this->assertSame(['insufficient_stock', 'insufficient_stock'], collect($r->json('data.items'))->pluck('issue')->all());
        $this->assertFalse($r->json('data.can_checkout'));
    }

    public function test_cart_view_uses_a_constant_number_of_queries(): void
    {
        foreach (range(1, 8) as $i) {
            $this->add($this->makeProduct(['M' => 9]), 'M')->assertCreated();
        }

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->cart()->assertJsonCount(8, 'data.items');
        $this->assertLessThanOrEqual(12, count(\DB::getQueryLog()));
    }
}
