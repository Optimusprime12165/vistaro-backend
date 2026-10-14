<?php

namespace Tests\Support;

use App\Enums\AdminRole;
use App\Enums\Size;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\SubCategory;
use App\Models\User;

/** Authenticate as a real token owner (guard cache is reset between identities) and build catalogue fixtures. */
trait CatalogTestHelpers
{
    protected function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this;
    }

    protected function actingAsAdmin(AdminRole $role = AdminRole::Admin): static
    {
        $admin = Admin::factory()->create(['role' => $role, 'email' => "a{$role->value}" . uniqid() . '@vistaro.test']);

        return $this->guest()->withToken($admin->createToken('t', ['admin'], now()->addHour())->plainTextToken);
    }

    protected function actingAsCustomer(?User $user = null): static
    {
        $user ??= User::factory()->create();

        return $this->guest()->withToken($user->createToken('t', ['customer'], now()->addDay())->plainTextToken);
    }

    /** A publicly visible product with the given online stock per size (and optional colours). */
    protected function makeProduct(array $stock = ['M' => 20], array $attrs = [], array $colors = []): Product
    {
        $sub = isset($attrs['sub_category_id']) ? SubCategory::findOrFail($attrs['sub_category_id']) : SubCategory::factory()->create();
        $p = Product::factory()->create(['sub_category_id' => $sub->id] + $attrs);

        foreach ($stock as $size => $qty) {
            ProductSize::factory()->for($p)->size(Size::from((string) $size))->quantity($qty)->create();
        }
        foreach ($colors as $i => [$name, $hex]) {
            $p->colors()->create(['name' => $name, 'hex' => $hex, 'sort_order' => $i]);
        }

        return $p->refresh();
    }

    protected function productPayload(array $override = []): array
    {
        return $override + [
            'sub_category_id' => SubCategory::factory()->create()->id,
            'name' => 'Test Tee', 'sku' => 'VS-TST-001', 'price' => 999, 'mrp' => 1499,
        ];
    }

    /** A real 1x1 PNG so MIME sniffing sees an image (no GD extension needed). */
    protected function pngBytes(int $padKb = 0): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==') . str_repeat("\0", $padKb * 1024);
    }

    /**
     * A REAL uploaded temp file. (Laravel's UploadedFile::fake() reports its MIME type from the file NAME, so it cannot
     * prove that content sniffing rejects a text file renamed to .png.)
     */
    protected function upload(string $name = 'a.png', ?string $content = null): \Illuminate\Http\UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vistaro');
        file_put_contents($tmp, $content ?? $this->pngBytes());

        return new \Illuminate\Http\UploadedFile($tmp, $name, null, null, true);
    }

    // ---------------------------------------------------------------- commerce

    /** A signed-in-able customer with a default address. @return array{0:User,1:\App\Models\Address} */
    protected function shopper(): array
    {
        $user = User::factory()->create();

        return [$user, \App\Models\Address::factory()->for($user)->create(['is_default' => true, 'phone' => '+919876543210'])];
    }

    protected function addToCart(User $user, Product $p, string $size, int $qty = 1, ?int $colorId = null): void
    {
        $this->actingAsCustomer($user)->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'size' => $size, 'quantity' => $qty, 'color_id' => $colorId])->assertCreated();
    }

    protected function placeOrder(User $user, \App\Models\Address $address, string $method = 'cod', ?string $key = null, array $extra = [])
    {
        return $this->actingAsCustomer($user)->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => $method] + $extra, ['Idempotency-Key' => $key ?? (string) \Illuminate\Support\Str::uuid()]);
    }

    protected function stock(Product $p, string $size): array
    {
        $ps = ProductSize::where(['product_id' => $p->id, 'size' => $size])->firstOrFail();

        return [$ps->quantity, $ps->reserved];
    }
}
