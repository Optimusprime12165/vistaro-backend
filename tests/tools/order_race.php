<?php

/**
 * Concurrency probe for order placement. Run MANY copies in parallel against a real MySQL/MariaDB DEVELOPMENT database.
 *   php tests/tools/order_race.php setup <stock> <buyers>      -> prints JSON {product_id, users:[ids], address_ids:{...}}
 *   php tests/tools/order_race.php place <userId> <key>         -> prints CREATED | REPLAYED | REFUSED:<http status> | CRASH:<class>
 *   php tests/tools/order_race.php report <productId>           -> prints stock + order counts
 *   php tests/tools/order_race.php cleanup                       -> removes everything created by `setup`
 * Refuses to run outside local/testing.
 */

use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\IdempotencyKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\Commerce\OrderService;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! $app->environment('local', 'testing')) {
    fwrite(STDERR, "refusing to run outside local/testing\n");
    exit(2);
}

[, $mode, $a, $b] = $argv + [null, null, null, null];

if ($mode === 'setup') {
    $sub = SubCategory::query()->firstOrFail();
    $product = Product::factory()->create(['sub_category_id' => $sub->id, 'sku' => 'RACE-' . uniqid(), 'price' => 1000, 'mrp' => 1200]);
    $ps = ProductSize::factory()->for($product)->state(['size' => 'M', 'quantity' => (int) $a])->create();
    $users = [];
    for ($i = 0; $i < (int) $b; $i++) {
        $u = User::factory()->create(['mobile' => '+9199999' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT)]);
        Address::factory()->for($u)->create(['is_default' => true]);
        Cart::query()->create(['user_id' => $u->id])->items()->create(['product_size_id' => $ps->id, 'quantity' => 1]);
        $users[] = $u->id;
    }
    echo json_encode(['product_id' => $product->id, 'users' => $users]) . "\n";
    exit(0);
}

if ($mode === 'setup-multi') {      // <buyers> buyers, two products with plenty of stock, carts built in opposite orders
    $sub = SubCategory::query()->firstOrFail();
    $sizes = [];
    foreach (['A', 'B'] as $tag) {
        $p = Product::factory()->create(['sub_category_id' => $sub->id, 'sku' => "RACE-{$tag}-" . uniqid(), 'price' => 1000, 'mrp' => 1200]);
        $sizes[$tag] = ProductSize::factory()->for($p)->state(['size' => 'M', 'quantity' => 1000])->create();
    }
    $users = [];
    for ($i = 0; $i < (int) $a; $i++) {
        $u = User::factory()->create(['mobile' => '+9199999' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT)]);
        Address::factory()->for($u)->create(['is_default' => true]);
        $cart = Cart::query()->create(['user_id' => $u->id]);
        foreach (($i % 2 === 0 ? ['A', 'B'] : ['B', 'A']) as $tag) {
            $cart->items()->create(['product_size_id' => $sizes[$tag]->id, 'quantity' => 2]);
        }
        $users[] = $u->id;
    }
    echo json_encode(['product_id' => $sizes['A']->product_id, 'product_b' => $sizes['B']->product_id, 'users' => $users]) . "\n";
    exit(0);
}

if ($mode === 'report') {
    $ps = ProductSize::where('product_id', (int) $a)->first();
    $orders = Order::whereHas('items', fn ($q) => $q->where('product_id', (int) $a))->count();
    $moves = InventoryMovement::where('product_id', (int) $a)->where('type', 'order_reserved')->count();
    echo "quantity={$ps->quantity} reserved={$ps->reserved} orders={$orders} reservation_movements={$moves}\n";
    exit(0);
}

if ($mode === 'cleanup') {
    $ids = User::where('mobile', 'like', '+9199999%')->pluck('id');
    $products = Product::where('sku', 'like', 'RACE-%')->pluck('id');
    Order::whereIn('user_id', $ids)->delete();
    IdempotencyKey::whereIn('user_id', $ids)->delete();
    Cart::whereIn('user_id', $ids)->delete();
    Address::whereIn('user_id', $ids)->delete();
    User::whereIn('id', $ids)->delete();
    InventoryMovement::whereIn('product_id', $products)->delete();
    Product::whereIn('id', $products)->delete();
    echo "cleaned\n";
    exit(0);
}

// mode === 'place'
$user = User::findOrFail((int) $a);
$address = $user->addresses()->firstOrFail();

try {
    $res = $app->make(OrderService::class)->place($user, $address, PaymentMethod::Cod, (string) $b);
    echo ($res['replayed'] ? 'REPLAYED' : 'CREATED') . "\n";
} catch (ApiException $e) {
    echo 'REFUSED:' . $e->status() . "\n";
} catch (Throwable $e) {
    echo 'CRASH:' . $e::class . ':' . substr($e->getMessage(), 0, 120) . "\n";
}
