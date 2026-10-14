<?php

namespace App\Services\Commerce;

use App\Enums\Size;
use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
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

    public function add(User $user, int $productId, Size $size, ?int $colorId, int $quantity): array
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
            $size = isset($changes['size']) ? Size::from($changes['size']) : $item->productSize->size;
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
    private function resolveTarget(int $productId, Size $size, ?int $colorId): array
    {
        $product = $this->catalog->visible()->with(['sizes', 'colors:id,product_id'])->find($productId);

        if (! $product) {
            throw ValidationException::withMessages(['product_id' => ['This product is not available.']]);
        }

        $ps = $product->sizes->firstWhere('size', $size);
        if (! $ps) {
            throw ValidationException::withMessages(['size' => ["This product is not sold in size {$size->value}."]]);
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
                $left > 0 ? "Only {$left} left in size {$ps->size->value}." : "Size {$ps->size->value} is out of stock.",
                ['quantity' => [$left > 0 ? "Only {$left} left." : 'Out of stock.']],
            );
        }
    }
}
