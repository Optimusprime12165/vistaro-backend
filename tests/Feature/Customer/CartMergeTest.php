<?php

namespace Tests\Feature\Customer;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

/**
 * POST /api/v1/cart/merge: folds the browser's guest cart into the signed-in customer's cart after login.
 * Policy: resulting quantity = max(server, guest), capped by the per-line maximum and by live stock; retry-safe.
 */
class CartMergeTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private const URL = '/api/v1/cart/merge';

    /** @param list<array<string,mixed>> $lines */
    private function merge(User $user, array $lines, array $extra = []): TestResponse
    {
        return $this->actingAsCustomer($user)->postJson(self::URL, ['lines' => $lines] + $extra);
    }

    private function line(Product $p, string $size = 'M', int $qty = 1, ?int $colorId = null): array
    {
        return ['product_id' => $p->id, 'size' => $size, 'color_id' => $colorId, 'quantity' => $qty];
    }

    private function colour(Product $product, string $name = 'Navy'): ProductColor
    {
        return ProductColor::query()->create(['product_id' => $product->id, 'name' => $name, 'hex' => '#1f2a44', 'sort_order' => 0]);
    }

    /** Puts a line straight into the customer's cart (bypasses the API, so any state can be set up). */
    private function seed(User $user, Product $p, string $size, int $qty, ?int $colorId = null): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $ps = ProductSize::query()->where(['product_id' => $p->id, 'size' => $size])->firstOrFail();

        return $cart->items()->create(['product_size_id' => $ps->id, 'product_color_id' => $colorId, 'quantity' => $qty]);
    }

    /** @return array<string,int> "productId|size|colourId-or-dash" => quantity, straight from the database */
    private function dbLines(User $user): array
    {
        $out = [];
        $cart = Cart::query()->where('user_id', $user->id)->first();
        foreach ($cart ? $cart->items()->with('productSize')->get() : [] as $i) {
            $out["{$i->productSize->product_id}|{$i->productSize->size}|" . ($i->product_color_id ?? '-')] = (int) $i->quantity;
        }
        ksort($out);

        return $out;
    }

    private function key(Product $p, string $size = 'M', ?int $colorId = null): string
    {
        return "{$p->id}|{$size}|" . ($colorId ?? '-');
    }

    // ---------------------------------------------------------------- authentication

    public function test_it_requires_authentication(): void
    {
        $p = $this->makeProduct(['M' => 10]);
        $body = ['lines' => [$this->line($p, 'M', 1)]];

        $this->guest()->postJson(self::URL, $body)->assertStatus(401)->assertJsonPath('success', false);
        $this->guest()->withToken('not-a-real-token')->postJson(self::URL, $body)->assertStatus(401);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    // ---------------------------------------------------------------- the max(server, guest) policy

    public function test_a_new_line_is_added_with_the_guest_quantity(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);

        $res = $this->merge($user, [$this->line($p, 'M', 3)])->assertOk();

        $res->assertJsonPath('message', 'Cart merged.')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product.id', $p->id)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.totals.subtotal', '3000.00');
        $this->assertSame([$this->key($p) => 3], $this->dbLines($user));
    }

    public function test_an_existing_line_becomes_the_maximum_of_server_and_guest_never_the_sum(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 20]);
        $this->addToCart($user, $p, 'M', 2)->assertSuccessful();

        $this->merge($user, [$this->line($p, 'M', 5)])->assertOk()->assertJsonPath('data.items.0.quantity', 5);   // guest higher: 5, not 7
        $this->assertSame([$this->key($p) => 5], $this->dbLines($user));

        $this->merge($user, [$this->line($p, 'M', 2)])->assertOk()->assertJsonPath('data.items.0.quantity', 5);   // guest lower: server kept, not 7
        $this->merge($user, [$this->line($p, 'M', 5)])->assertOk()->assertJsonPath('data.items.0.quantity', 5);   // equal: unchanged, not 10
        $this->assertSame([$this->key($p) => 5], $this->dbLines($user));
    }

    public function test_a_merge_never_reduces_a_server_line_even_when_stock_has_since_fallen(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 10]);
        $this->addToCart($user, $p, 'M', 3)->assertSuccessful();
        ProductSize::query()->where(['product_id' => $p->id, 'size' => 'M'])->update(['quantity' => 1]);

        $res = $this->merge($user, [$this->line($p, 'M', 1)])->assertOk();

        $this->assertSame([$this->key($p) => 3], $this->dbLines($user));
        $res->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.issue', 'insufficient_stock')   // the cart reports it; the merge does not silently shrink the line
            ->assertJsonPath('data.can_checkout', false);
    }

    // ---------------------------------------------------------------- caps

    public function test_the_result_is_capped_by_live_stock(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 4]);

        $this->merge($user, [$this->line($p, 'M', 9)])->assertOk()->assertJsonPath('data.items.0.quantity', 4);
        $this->assertSame([$this->key($p) => 4], $this->dbLines($user));
        $this->assertSame([4, 0], $this->stock($p, 'M'), 'a merge must never reserve or consume stock');
    }

    public function test_the_stock_cap_counts_units_held_in_other_lines_of_the_same_size(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 6]);
        $navy = $this->colour($p, 'Navy');
        $olive = $this->colour($p, 'Olive');
        $this->seed($user, $p, 'M', 4, $navy->id);                  // 4 of the 6 units already sit in the Navy line

        $this->merge($user, [$this->line($p, 'M', 5, $olive->id)])->assertOk();

        $this->assertEquals([
            $this->key($p, 'M', $navy->id) => 4,
            $this->key($p, 'M', $olive->id) => 2,                   // only 6 - 4 = 2 left for the Olive line
        ], $this->dbLines($user));
    }

    public function test_the_result_is_capped_by_the_per_line_maximum(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 100]);
        $max = (int) config('vistaro.cart.max_line_quantity');
        $part = $max - 2;                                           // each line is valid on its own, together they exceed the maximum

        $this->merge($user, [$this->line($p, 'M', $part), $this->line($p, 'M', $part)])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', $max);
        $this->assertSame([$this->key($p) => $max], $this->dbLines($user));
    }

    public function test_a_sold_out_size_is_skipped(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 0, 'L' => 5]);

        $this->merge($user, [$this->line($p, 'M', 2), $this->line($p, 'L', 2)])->assertOk();

        $this->assertSame([$this->key($p, 'L') => 2], $this->dbLines($user));
    }

    // ---------------------------------------------------------------- retry safety

    public function test_calling_the_same_merge_twice_gives_the_identical_cart(): void
    {
        [$user] = $this->shopper();
        $a = $this->makeProduct(['M' => 20, 'L' => 20]);
        $b = $this->makeProduct(['M' => 3]);
        $this->addToCart($user, $a, 'M', 2)->assertSuccessful();
        $lines = [$this->line($a, 'M', 4), $this->line($a, 'L', 3), $this->line($b, 'M', 9)];

        $first = $this->merge($user, $lines)->assertOk();
        $afterFirst = $this->dbLines($user);
        $second = $this->merge($user, $lines)->assertOk();
        $third = $this->merge($user, $lines)->assertOk();

        $this->assertSame($afterFirst, $this->dbLines($user));
        $this->assertSame(4, $afterFirst[$this->key($a)], 'max(server 2, guest 4), not the sum');
        $this->assertSame(3, $afterFirst[$this->key($b)], 'capped by stock once, not re-added');
        $this->assertSame($first->json('data.items'), $second->json('data.items'));
        $this->assertSame($first->json('data.totals'), $second->json('data.totals'));
        $this->assertSame($first->json('data.items'), $third->json('data.items'));
        $this->assertSame(3, CartItem::query()->count());
    }

    // ---------------------------------------------------------------- lines that changed or vanished

    public function test_vanished_or_changed_product_size_or_colour_is_skipped_without_failing_the_request(): void
    {
        [$user] = $this->shopper();
        $good = $this->makeProduct(['M' => 10]);
        $withDisabledSize = $this->makeProduct(['M' => 10]);
        ProductSize::factory()->disabled()->create(['product_id' => $withDisabledSize->id, 'size' => 'L', 'quantity' => 5, 'reserved' => 0]);
        $archived = $this->makeProduct(['M' => 10], ['status' => ProductStatus::Archived]);
        $unavailable = $this->makeProduct(['M' => 10], ['is_available' => false]);
        $coloured = $this->makeProduct(['M' => 10]);
        $this->colour($coloured);
        $foreignColour = $this->colour($this->makeProduct(['M' => 1]), 'Olive');

        $res = $this->merge($user, [
            ['product_id' => 999999, 'size' => 'M', 'quantity' => 1],            // product deleted
            $this->line($archived, 'M', 1),                                      // product archived
            $this->line($unavailable, 'M', 1),                                   // product switched off for online sale
            $this->line($good, 'XXL', 1),                                        // size no longer sold
            $this->line($withDisabledSize, 'L', 1),                              // size disabled
            $this->line($coloured, 'M', 1),                                      // colours were added: one is now required
            $this->line($coloured, 'M', 1, $foreignColour->id),                  // colour does not belong to the product
            $this->line($good, 'M', 1, $foreignColour->id),                      // product has no colours
            $this->line($good, 'M', 2),                                          // the one valid line, AFTER the bad ones
        ])->assertOk();

        $res->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 2);
        $this->assertSame([$this->key($good) => 2], $this->dbLines($user));
    }

    // ---------------------------------------------------------------- duplicates

    public function test_duplicate_guest_lines_for_the_same_product_size_and_colour_are_folded_into_one(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 50]);

        $this->merge($user, [$this->line($p, 'M', 2), $this->line($p, 'm', 3), $this->line($p, ' M ', 1)])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 6);
        $this->assertSame([$this->key($p) => 6], $this->dbLines($user));
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_the_same_product_and_size_in_different_colours_stays_on_separate_lines(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 50]);
        $navy = $this->colour($p, 'Navy');
        $olive = $this->colour($p, 'Olive');

        $this->merge($user, [$this->line($p, 'M', 2, $navy->id), $this->line($p, 'M', 3, $olive->id), $this->line($p, 'M', 1, $navy->id)])->assertOk();

        $this->assertEquals([
            $this->key($p, 'M', $navy->id) => 3,
            $this->key($p, 'M', $olive->id) => 3,
        ], $this->dbLines($user));
    }

    // ---------------------------------------------------------------- isolation

    public function test_customers_can_never_see_or_change_each_others_cart(): void
    {
        [$alice] = $this->shopper();
        [$bob] = $this->shopper();
        $p = $this->makeProduct(['M' => 20], ['price' => '1000.00', 'mrp' => '1200.00']);
        $alicesLine = $this->seed($alice, $p, 'M', 2);

        // Bob sees an empty cart and merges into his OWN cart; Alice's line is untouched even for the same product + size.
        $this->actingAsCustomer($bob)->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
        $res = $this->merge($bob, [$this->line($p, 'M', 5)])->assertOk();

        $res->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 5);
        $this->assertNotSame($alicesLine->id, $res->json('data.items.0.id'));
        $this->assertSame([$this->key($p) => 2], $this->dbLines($alice));
        $this->assertSame([$this->key($p) => 5], $this->dbLines($bob));
        $this->assertNotSame(
            Cart::query()->where('user_id', $alice->id)->value('id'),
            Cart::query()->where('user_id', $bob->id)->value('id'),
        );

        // Bob cannot address Alice's line by id (404 = it does not exist for him), and clearing his cart leaves hers alone.
        $this->actingAsCustomer($bob)->patchJson("/api/v1/cart/items/{$alicesLine->id}", ['quantity' => 9])->assertStatus(404);
        $this->actingAsCustomer($bob)->deleteJson("/api/v1/cart/items/{$alicesLine->id}")->assertStatus(404);
        $this->actingAsCustomer($bob)->deleteJson('/api/v1/cart')->assertOk();
        $this->assertSame([$this->key($p) => 2], $this->dbLines($alice));

        // ... and Alice's cart view never contains Bob's lines.
        $this->merge($bob, [$this->line($p, 'M', 7)])->assertOk();
        $this->actingAsCustomer($alice)->getJson('/api/v1/cart')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 2);
    }

    // ---------------------------------------------------------------- validation and trust

    public function test_an_invalid_body_returns_422_and_changes_nothing(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 100]);
        $line = $this->line($p, 'M', 1);

        $this->merge($user, array_fill(0, 51, $line))->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $this->actingAsCustomer($user)->postJson(self::URL, [])->assertStatus(422);
        $this->merge($user, [['product_id' => $p->id, 'size' => 'M', 'quantity' => 0]])->assertStatus(422);
        $this->merge($user, [['product_id' => $p->id, 'size' => 'M', 'quantity' => (int) config('vistaro.cart.max_line_quantity') + 1]])->assertStatus(422);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_client_supplied_prices_are_ignored(): void
    {
        [$user] = $this->shopper();
        $p = $this->makeProduct(['M' => 10], ['price' => '1000.00', 'mrp' => '1200.00']);

        $this->merge($user, [$this->line($p, 'M', 2) + ['price' => '1.00', 'unit_price' => '1.00', 'total' => '0.01']], ['total' => '0.01', 'subtotal' => '0.01'])
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '1000.00')
            ->assertJsonPath('data.totals.subtotal', '2000.00');
    }
}
