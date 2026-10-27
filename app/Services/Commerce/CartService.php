<?php

namespace App\Services\Commerce;

use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use App\Support\SizeCode;
use App\Services\Catalog\StorefrontCatalogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One cart per customer. Every write locks the cart row first, so two requests of the same customer are serialised
 * (this is what makes "merge duplicate lines" safe even though SQL NULL colours defeat the unique index).
 * A line is only ever (product size, optional colour, quantity): prices are never stored, they come from PricingService.
 */
final class CartService
{
    public function __construct(private readonly StorefrontCatalogService $catalog, private readonly PricingService $pricing)
    {
    }

    public const RELATIONS = [
        'productSize.product.category:id,name,slug,is_active',
        'productSize.product.subCategory:id,name,slug,is_active',
        'productSize.product.primaryImage',
        'color',
    ];

    /** @return array{id:?int,items:list<array<string,mixed>>,totals:array<string,mixed>,issues:list<array<string,mixed>>,can_checkout:bool} */
    public function view(User $user): array
    {
        $cart = Cart::query()->where('user_id', $user->id)->first();

        return ['id' => $cart?->id] + $this->pricing->build($this->items($cart));
    }

    /** @return Collection<int,CartItem> */
    public function items(?Cart $cart): Collection
    {
        return $cart ? $cart->items()->with(self::RELATIONS)->orderBy('id')->get() : new Collection();
    }

    public function add(User $user, int $productId, string $size, ?int $colorId, int $quantity): array
    {
        DB::transaction(function () use ($user, $productId, $size, $colorId, $quantity) {
            $cart = $this->lockedCart($user);
            [$ps] = $this->resolveTarget($productId, $size, $colorId);

            $line = $this->findLine($cart, $ps->id, $colorId);
            $this->assertQuantity(($line?->quantity ?? 0) + $quantity, $ps, $this->otherUnits($cart, $ps->id, $line?->id));

            $line
                ? $line->update(['quantity' => $line->quantity + $quantity])
                : $cart->items()->create(['product_size_id' => $ps->id, 'product_color_id' => $colorId, 'quantity' => $quantity]);
        }, 3);

        return $this->view($user);
    }

    /** Change quantity and/or size and/or colour of a line. Moving onto an existing identical line merges the two. */
    public function update(User $user, int $itemId, array $changes): array
    {
        DB::transaction(function () use ($user, $itemId, $changes) {
            $cart = $this->lockedCart($user);
            $item = $cart->items()->with('productSize')->findOrFail($itemId);          // 404 for lines of other customers

            $productId = $item->productSize->product_id;
            $size = isset($changes['size']) ? SizeCode::normalize($changes['size']) : $item->productSize->size;
            $colorId = array_key_exists('color_id', $changes) ? $changes['color_id'] : $item->product_color_id;
            $quantity = (int) ($changes['quantity'] ?? $item->quantity);

            [$ps] = $this->resolveTarget($productId, $size, $colorId);
            $other = $this->findLine($cart, $ps->id, $colorId, $item->id);

            if ($other) {                                                              // identical line exists: merge into it
                $this->assertQuantity($other->quantity + $quantity, $ps, $this->otherUnits($cart, $ps->id, $other->id, $item->id));
                $other->update(['quantity' => $other->quantity + $quantity]);
                $item->delete();

                return;
            }

            $this->assertQuantity($quantity, $ps, $this->otherUnits($cart, $ps->id, $item->id));
            $item->update(['product_size_id' => $ps->id, 'product_color_id' => $colorId, 'quantity' => $quantity]);
        }, 3);

        return $this->view($user);
    }

    public function remove(User $user, int $itemId): array
    {
        DB::transaction(function () use ($user, $itemId) {
            $this->lockedCart($user)->items()->findOrFail($itemId)->delete();
        });

        return $this->view($user);
    }

    public function clear(User $user): array
    {
        DB::transaction(fn () => $this->lockedCart($user)->items()->delete());

        return $this->view($user);
    }

    // ---------------------------------------------------------------- guest cart (lines live in the visitor's browser)

    /**
     * Prices lines held in a guest's browser. NOTHING is stored and no customer data is read: only public catalogue
     * data is returned, priced by the same PricingService as a real cart. Lines that can no longer be bought are
     * dropped and reported in `dropped`.
     *
     * @param list<array{product_id:int|string,size:string,color_id?:int|string|null,quantity:int|string}> $lines
     * @return array<string,mixed>
     */
    public function previewGuest(array $lines): array
    {
        $max = (int) config('vistaro.cart.max_line_quantity');
        $wanted = $this->normaliseGuestLines($lines);

        $products = $wanted === [] ? new Collection() : $this->catalog->visible()
            ->with(['sizes', 'colors', 'category:id,name,slug,is_active', 'subCategory:id,name,slug,is_active', 'primaryImage'])
            ->whereIn('id', array_values(array_unique(array_column($wanted, 'product_id'))))
            ->get()->keyBy('id');

        $items = new Collection();
        $dropped = [];

        foreach ($wanted as $idx => $l) {
            $product = $products->get($l['product_id']);
            $size = $product?->sizes->firstWhere('size', $l['size']);
            $color = $product && $l['color_id'] !== null ? $product->colors->firstWhere('id', $l['color_id']) : null;
            $colorOk = $product && ($product->colors->isEmpty() ? $l['color_id'] === null : $color !== null);

            if (! $product || ! $size || ! $size->is_enabled || ! $colorOk) {
                $dropped[] = ['product_id' => $l['product_id'], 'size' => $l['size'], 'reason' => 'unavailable'];

                continue;
            }

            $size->setRelation('product', $product);
            $item = new CartItem(['product_size_id' => $size->id, 'product_color_id' => $color?->id, 'quantity' => min($l['quantity'], $max)]);
            $item->id = $idx + 1;                               // synthetic: position in the (folded) request, so the browser can map a line back
            $item->setRelation('productSize', $size);
            $item->setRelation('color', $color);
            $items->push($item);
        }

        return ['id' => null, 'dropped' => $dropped] + $this->pricing->build($items);
    }

    /**
     * Merges a guest's browser cart into the signed-in customer's cart after login.
     * Policy (documented, deterministic, RETRY-SAFE): for every guest line the resulting quantity is
     * max(quantity already in the customer's cart, guest quantity), capped by the per-line maximum and by live stock.
     * Running the same merge twice therefore changes nothing the second time (a sum policy would double the quantities).
     * Lines that are no longer purchasable are skipped. Prices are never read from the client.
     *
     * @param list<array{product_id:int|string,size:string,color_id?:int|string|null,quantity:int|string}> $lines
     */
    public function mergeGuest(User $user, array $lines): array
    {
        $max = (int) config('vistaro.cart.max_line_quantity');
        $wanted = $this->normaliseGuestLines($lines);

        DB::transaction(function () use ($user, $wanted, $max) {
            $cart = $this->lockedCart($user);

            foreach ($wanted as $l) {
                try {
                    [$ps] = $this->resolveTarget($l['product_id'], $l['size'], $l['color_id']);
                } catch (ValidationException) {
                    continue;                                   // product/size/colour changed or vanished
                }

                $line = $this->findLine($cart, $ps->id, $l['color_id']);
                $room = max(0, min($max, $ps->quantity - $this->otherUnits($cart, $ps->id, $line?->id)));
                $target = min(max($line?->quantity ?? 0, $l['quantity']), $room);

                if ($target <= 0) {
                    continue;
                }
                if ($line) {
                    if ($target > $line->quantity) {
                        $line->update(['quantity' => $target]);
                    }
                } else {
                    $cart->items()->create(['product_size_id' => $ps->id, 'product_color_id' => $l['color_id'], 'quantity' => $target]);
                }
            }
        }, 3);

        return $this->view($user);
    }

    /**
     * Sanitises client-supplied lines and folds duplicates (same product + size + colour) into one line.
     *
     * @return list<array{product_id:int,size:string,color_id:?int,quantity:int}>
     */
    private function normaliseGuestLines(array $lines): array
    {
        $max = (int) config('vistaro.cart.max_line_quantity');
        $out = [];

        foreach (array_slice($lines, 0, 50) as $l) {
            if (! is_array($l) || ! isset($l['product_id'], $l['size'], $l['quantity']) || ! is_string($l['size'])) {
                continue;
            }
            $pid = (int) $l['product_id'];
            $qty = (int) $l['quantity'];
            $color = isset($l['color_id']) && $l['color_id'] !== '' ? (int) $l['color_id'] : null;
            if ($pid < 1 || $qty < 1) {
                continue;
            }
            $size = SizeCode::normalize($l['size']);
            $key = "{$pid}|{$size}|" . ($color ?? '-');
            $out[$key] = isset($out[$key])
                ? array_merge($out[$key], ['quantity' => min($max, $out[$key]['quantity'] + $qty)])
                : ['product_id' => $pid, 'size' => $size, 'color_id' => $color, 'quantity' => min($max, $qty)];
        }

        return array_values($out);
    }

    // ---------------------------------------------------------------- internals

    private function lockedCart(User $user): Cart
    {
        Cart::query()->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);   // unique(user_id) makes a race harmless

        return Cart::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function findLine(Cart $cart, int $productSizeId, ?int $colorId, ?int $exceptId = null): ?CartItem
    {
        return $cart->items()->where('product_size_id', $productSizeId)
            ->when($colorId === null, fn ($q) => $q->whereNull('product_color_id'), fn ($q) => $q->where('product_color_id', $colorId))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->first();
    }

    /**
     * The product must be purchasable now, the size must exist for it, and the colour must belong to it
     * (required when the product has colours, forbidden when it has none).
     *
     * @return array{0:ProductSize,1:Product}
     */
    private function resolveTarget(int $productId, string $size, ?int $colorId): array
    {
        $product = $this->catalog->visible()->with(['sizes', 'colors:id,product_id'])->find($productId);

        if (! $product) {
            throw ValidationException::withMessages(['product_id' => ['This product is not available.']]);
        }

        $size = SizeCode::normalize($size);
        $ps = $product->sizes->firstWhere('size', $size);
        if (! $ps) {
            throw ValidationException::withMessages(['size' => ["This product is not sold in size {$size}."]]);
        }
        if (! $ps->is_enabled) {                                   // a disabled size can never be put in a cart
            throw ValidationException::withMessages(['size' => ["Size {$size} is not available."]]);
        }

        if ($product->colors->isNotEmpty() && $colorId === null) {
            throw ValidationException::withMessages(['color_id' => ['Please choose a colour.']]);
        }
        if ($colorId !== null && ! $product->colors->contains('id', $colorId)) {
            throw ValidationException::withMessages(['color_id' => ['This colour does not belong to the selected product.']]);
        }

        return [$ps, $product];
    }

    /** Units of the same size already in the cart on OTHER lines (other colours), which share the same stock. */
    private function otherUnits(Cart $cart, int $productSizeId, ?int ...$exceptLineIds): int
    {
        return (int) $cart->items()->where('product_size_id', $productSizeId)->whereNotIn('id', array_filter($exceptLineIds))->sum('quantity');
    }

    private function assertQuantity(int $total, ProductSize $ps, int $otherUnits = 0): void
    {
        $max = (int) config('vistaro.cart.max_line_quantity');

        if ($total > $max) {
            throw ValidationException::withMessages(['quantity' => ["You can buy at most {$max} of one item."]]);
        }
        $available = $ps->quantity - $otherUnits;
        if ($total > $available) {
            $left = max(0, $available);
            throw new InsufficientStockException(
                $left > 0 ? "Only {$left} left in size {$ps->size}." : "Size {$ps->size} is out of stock.",
                ['quantity' => [$left > 0 ? "Only {$left} left." : 'Out of stock.']],
            );
        }
    }
}
