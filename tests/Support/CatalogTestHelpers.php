<?php

namespace Tests\Support;

use App\Enums\AdminRole;
use App\Models\Address;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use App\Support\SizeCode;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use App\Services\Auth\TokenService;

/**
 * RECONSTRUCTED helper (updated for shared stock, dynamic sizes and the single-Super-Admin role model). The original tests/Support/CatalogTestHelpers.php (Phases 1-6) was NOT in the supplied files,
 * so this implements exactly the helper API that the Phase 7 tests call:
 * actingAsAdmin, actingAsCustomer, guest, stock, shopper, makeProduct, addToCart, placeOrder.
 */
trait CatalogTestHelpers
{
    protected function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->withoutToken();

        return $this;
    }

    /** Real bearer token (same issuer as production), so guard/provider/ability behaviour is the real one. */
    private function bearer(\Illuminate\Database\Eloquent\Model $who, string $ability): static
    {
        $this->guest();
        $token = app(TokenService::class)->issue($who, $ability, 60, 'test');

        return $this->withToken($token->plainTextToken);
    }

    /** Exactly one active Super Admin may exist, so the helper re-uses it; Staff accounts are created fresh each call. */
    protected function actingAsAdmin(AdminRole $role = AdminRole::SuperAdmin): static
    {
        $admin = $role === AdminRole::SuperAdmin
            ? (Admin::query()->where('role', 'super_admin')->where('is_active', true)->first() ?? Admin::factory()->superAdmin()->create())
            : Admin::factory()->create(['role' => $role->value]);

        return $this->bearer($admin, 'admin');
    }

    protected function actingAsCustomer(User $user): static
    {
        return $this->bearer($user, 'customer');
    }

    /** @return array{0:User,1:Address} a customer with one (default) saved address */
    protected function shopper(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        return [$user, $address];
    }

    /** @param array<string,int> $stock size => quantity */
    protected function makeProduct(array $stock = ['M' => 10], array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);
        foreach ($stock as $size => $qty) {
            ProductSize::factory()->create(['product_id' => $product->id, 'size' => SizeCode::normalize((string) $size), 'quantity' => $qty, 'reserved' => 0]);
        }

        return $product;
    }

    /** @return array{0:int,1:int} [sellable quantity, reserved] */
    protected function stock(Product $product, string $size): array
    {
        $row = ProductSize::query()->where(['product_id' => $product->id, 'size' => $size])->firstOrFail();

        return [(int) $row->quantity, (int) $row->reserved];
    }

    protected function addToCart(User $user, Product $product, string $size, int $qty = 1): TestResponse
    {
        return $this->actingAsCustomer($user)->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'size' => $size, 'quantity' => $qty]);
    }

    protected function placeOrder(User $user, Address $address, string $method = 'cod', ?string $key = null, ?array $body = null): TestResponse
    {
        return $this->actingAsCustomer($user)->postJson(
            '/api/v1/orders',
            $body ?? ['address_id' => $address->id, 'payment_method' => $method],
            ['Idempotency-Key' => $key ?? 'test-' . Str::random(16)],
        );
    }
}
