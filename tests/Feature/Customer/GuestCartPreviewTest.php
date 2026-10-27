<?php

namespace Tests\Feature\Customer;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * POST /api/v1/cart/guest/preview: public, stateless pricing of a browser-held guest cart.
 * Prices always come from the database; nothing is stored; lines that cannot be bought are reported in `dropped`.
 */
class GuestCartPreviewTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private const URL = '/api/v1/cart/guest/preview';

    /** @param list<array<string,mixed>> $lines */
    private function preview(array $lines, array $extra = []): TestResponse
    {
        return $this->guest()->postJson(self::URL, ['lines' => $lines] + $extra);
    }

    private function colour(Product $product, string $name = 'Navy'): ProductColor
    {
        return ProductColor::query()->create(['product_id' => $product->id, 'name' => $name, 'hex' => '#1f2a44', 'sort_order' => 0]);
    }

    private function line(Product $p, string $size = 'M', int $qty = 1, ?int $colorId = null): array
    {
        return ['product_id' => $p->id, 'size' => $size, 'color_id' => $colorId, 'quantity' => $qty];
    }

    // ---------------------------------------------------------------- pricing without a token

    public function test_works_without_a_token_and_returns_server_calculated_prices_and_totals(): void
    {
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);

        $res = $this->preview([$this->line($p, 'M', 2)])->assertOk();

        $res->assertJsonPath('success', true)
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.dropped', [])
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product.id', $p->id)
            ->assertJsonPath('data.items.0.size', 'M')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '1000.00')
            ->assertJsonPath('data.items.0.mrp', '1200.00')
            ->assertJsonPath('data.items.0.line_subtotal', '2000.00')
            ->assertJsonPath('data.items.0.available', true)
            ->assertJsonPath('data.totals.item_count', 2)
            ->assertJsonPath('data.totals.subtotal', '2000.00')
            ->assertJsonPath('data.totals.mrp_total', '2400.00')
            ->assertJsonPath('data.totals.discount_total', '400.00')
            ->assertJsonPath('data.can_checkout', true);

        $t = $res->json('data.totals');
        $this->assertSame(
            number_format((float) $t['subtotal'] + (float) $t['shipping_fee'], 2, '.', ''),
            $t['total'],
            'total must be subtotal + shipping fee',
        );
    }

    public function test_an_empty_guest_cart_is_valid_and_prices_to_zero(): void
    {
        $res = $this->preview([])->assertOk();

        $res->assertJsonPath('data.items', [])
            ->assertJsonPath('data.dropped', [])
            ->assertJsonPath('data.totals.subtotal', '0.00')
            ->assertJsonPath('data.totals.total', '0.00')
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_size_codes_are_normalised_like_the_real_cart(): void
    {
        $p = $this->makeProduct(['M' => 5]);

        $this->preview([['product_id' => $p->id, 'size' => ' m ', 'quantity' => 1]])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.size', 'M');
    }

    public function test_a_valid_colour_of_the_product_is_returned_on_the_line(): void
    {
        $p = $this->makeProduct(['M' => 5]);
        $c = $this->colour($p);

        $this->preview([$this->line($p, 'M', 1, $c->id)])
            ->assertOk()
            ->assertJsonPath('data.items.0.color.id', $c->id)
            ->assertJsonPath('data.items.0.color.name', 'Navy');
    }

    // ---------------------------------------------------------------- client-supplied money is never trusted

    public function test_client_supplied_prices_and_totals_are_ignored(): void
    {
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);

        $line = $this->line($p, 'M', 2) + [
            'price' => '1.00', 'unit_price' => '1.00', 'mrp' => '1.00', 'discount_percent' => 99,
            'line_subtotal' => '0.01', 'total' => '0.01',
        ];
        $res = $this->preview([$line], ['subtotal' => '0.01', 'total' => '0.01', 'shipping_fee' => '0.00', 'discount_total' => '99999.00'])->assertOk();

        $res->assertJsonPath('data.items.0.unit_price', '1000.00')
            ->assertJsonPath('data.items.0.mrp', '1200.00')
            ->assertJsonPath('data.items.0.line_subtotal', '2000.00')
            ->assertJsonPath('data.totals.subtotal', '2000.00')
            ->assertJsonPath('data.totals.discount_total', '400.00');
        $this->assertNotSame('0.01', $res->json('data.totals.total'));
    }

    public function test_prices_follow_the_catalogue_at_call_time(): void
    {
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);
        $this->preview([$this->line($p, 'M', 1)])->assertOk()->assertJsonPath('data.items.0.unit_price', '1000.00');

        Product::query()->whereKey($p->id)->update(['price' => '1100.00']);

        $this->preview([$this->line($p, 'M', 1)])->assertOk()->assertJsonPath('data.items.0.unit_price', '1100.00');
    }

    // ---------------------------------------------------------------- lines that cannot be bought are dropped

    public function test_unknown_product_unavailable_size_and_invalid_colour_are_dropped_and_not_priced(): void
    {
        $good = $this->makeProduct(['M' => 10]);
        $withDisabledSize = $this->makeProduct(['M' => 10]);
        ProductSize::factory()->disabled()->create(['product_id' => $withDisabledSize->id, 'size' => 'L', 'quantity' => 5, 'reserved' => 0]);
        $archived = $this->makeProduct(['M' => 10], ['status' => ProductStatus::Archived]);
        $coloured = $this->makeProduct(['M' => 10]);
        $this->colour($coloured);
        $foreignColour = $this->colour($this->makeProduct(['M' => 1]), 'Olive');

        $res = $this->preview([
            $this->line($good, 'M', 1),                                          // kept
            ['product_id' => 999999, 'size' => 'M', 'quantity' => 1],            // unknown product
            $this->line($good, 'XXL', 1),                                        // size the product is not sold in
            $this->line($withDisabledSize, 'L', 1),                              // size exists but is disabled
            $this->line($archived, 'M', 1),                                      // product hidden from the storefront
            $this->line($coloured, 'M', 1),                                      // product has colours, none supplied
            $this->line($coloured, 'M', 1, $foreignColour->id),                  // colour of a different product
            $this->line($good, 'M', 1, $foreignColour->id),                      // product has no colours, one supplied
        ])->assertOk();

        $res->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product.id', $good->id)
            ->assertJsonCount(7, 'data.dropped')
            ->assertJsonPath('data.totals.item_count', 1);
        foreach ($res->json('data.dropped') as $d) {
            $this->assertSame('unavailable', $d['reason']);
            $this->assertArrayHasKey('product_id', $d);
            $this->assertArrayHasKey('size', $d);
        }
        $this->assertContains(999999, array_column($res->json('data.dropped'), 'product_id'));
    }

    public function test_item_ids_are_the_position_of_the_line_in_the_request_so_the_browser_can_map_them_back(): void
    {
        $a = $this->makeProduct(['M' => 10]);
        $b = $this->makeProduct(['M' => 10]);

        $res = $this->preview([
            $this->line($a, 'M', 1),
            ['product_id' => 999999, 'size' => 'M', 'quantity' => 1],
            $this->line($b, 'M', 1),
        ])->assertOk();

        $this->assertSame([1, 3], array_column($res->json('data.items'), 'id'));
    }

    // ---------------------------------------------------------------- quantities

    public function test_a_single_line_above_the_per_line_maximum_is_rejected_by_validation(): void
    {
        $p = $this->makeProduct(['M' => 100]);
        $max = (int) config('vistaro.cart.max_line_quantity');

        $this->preview([$this->line($p, 'M', $max + 1)])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);
        $this->preview([$this->line($p, 'M', $max)])->assertOk()->assertJsonPath('data.items.0.quantity', $max);
    }

    public function test_duplicate_lines_are_folded_and_the_total_is_capped_at_the_per_line_maximum(): void
    {
        $p = $this->makeProduct(['M' => 100]);
        $max = (int) config('vistaro.cart.max_line_quantity');
        $half = intdiv($max, 2) + 1;                     // two of these always exceed the maximum

        $res = $this->preview([$this->line($p, 'M', $half), $this->line($p, 'm', $half)])->assertOk();

        $res->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', $max);
    }

    public function test_quantity_above_live_stock_is_reported_on_the_line_and_blocks_checkout_rather_than_being_clamped(): void
    {
        $p = $this->makeProduct(['M' => 3]);

        $res = $this->preview([$this->line($p, 'M', 5)])->assertOk();

        $res->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.available', false)
            ->assertJsonPath('data.items.0.issue', 'insufficient_stock')
            ->assertJsonCount(1, 'data.issues')
            ->assertJsonPath('data.issues.0.code', 'insufficient_stock')
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_a_sold_out_size_stays_on_the_list_flagged_out_of_stock(): void
    {
        $p = $this->makeProduct(['M' => 0]);

        $this->preview([$this->line($p, 'M', 1)])
            ->assertOk()
            ->assertJsonPath('data.items.0.available', false)
            ->assertJsonPath('data.items.0.issue', 'out_of_stock')
            ->assertJsonPath('data.can_checkout', false);
    }

    // ---------------------------------------------------------------- validation (422)

    public function test_more_than_fifty_lines_is_rejected_and_exactly_fifty_is_accepted(): void
    {
        $p = $this->makeProduct(['M' => 100]);
        $line = $this->line($p, 'M', 1);

        $this->preview(array_fill(0, 51, $line))->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $this->preview(array_fill(0, 50, $line))->assertOk();
    }

    public function test_an_invalid_body_returns_422(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $max = (int) config('vistaro.cart.max_line_quantity');
        $ok = $this->line($p, 'M', 1);

        $bad = [
            'lines key missing' => [],
            'lines is not an array' => ['lines' => 'nope'],
            'a line is not an object' => ['lines' => ['x']],
            'product_id missing' => ['lines' => [['size' => 'M', 'quantity' => 1]]],
            'product_id zero' => ['lines' => [['product_id' => 0] + $ok]],
            'product_id not a number' => ['lines' => [['product_id' => 'abc'] + $ok]],
            'size missing' => ['lines' => [['product_id' => $p->id, 'quantity' => 1]]],
            'size has illegal characters' => ['lines' => [['size' => '<script>'] + $ok]],
            'quantity missing' => ['lines' => [['product_id' => $p->id, 'size' => 'M']]],
            'quantity zero' => ['lines' => [['quantity' => 0] + $ok]],
            'quantity not a number' => ['lines' => [['quantity' => 'many'] + $ok]],
            'quantity above the maximum' => ['lines' => [['quantity' => $max + 1] + $ok]],
            'colour id zero' => ['lines' => [['color_id' => 0] + $ok]],
        ];

        foreach ($bad as $why => $body) {
            $this->assertSame(422, $this->guest()->postJson(self::URL, $body)->getStatusCode(), "expected 422 for: {$why}");
        }
    }

    // ---------------------------------------------------------------- stateless

    public function test_nothing_is_written_to_the_cart_tables_or_to_stock(): void
    {
        $p = $this->makeProduct(['M' => 10]);

        $this->preview([$this->line($p, 'M', 3)])->assertOk();
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);

        // ... also when a customer with an existing cart (and a token) calls it
        [$user] = $this->shopper();
        $this->addToCart($user, $p, 'M', 2)->assertSuccessful();
        $carts = Cart::query()->count();
        $items = CartItem::query()->count();
        $units = (int) CartItem::query()->sum('quantity');
        $stock = $this->stock($p, 'M');

        $this->preview([$this->line($p, 'M', 5)])->assertOk();
        $this->actingAsCustomer($user)->postJson(self::URL, ['lines' => [$this->line($p, 'M', 7)]])->assertOk();

        $this->assertSame($carts, Cart::query()->count());
        $this->assertSame($items, CartItem::query()->count());
        $this->assertSame($units, (int) CartItem::query()->sum('quantity'));
        $this->assertSame($stock, $this->stock($p, 'M'));
    }

    public function test_the_response_carries_no_customer_data(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 10]);

        $json = $this->actingAsCustomer($user)->postJson(self::URL, ['lines' => [$this->line($p, 'M', 1)]])->assertOk()->getContent();

        $this->assertStringNotContainsString((string) $user->email, $json);
        $this->assertStringNotContainsString((string) $user->mobile, $json);
    }
}
