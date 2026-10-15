<?php

namespace App\Services\Commerce;

use App\Enums\ProductStatus;
use App\Models\CartItem;
use App\Support\ImageUrl;
use App\Services\Settings\BusinessSettingsService;
use App\Support\Money;
use App\Support\StockDisplay;
use Illuminate\Support\Collection;

/**
 * The ONLY place that turns cart lines into prices and totals. Prices come from the products table at call time;
 * nothing the client sends (price, discount, total) is ever read. All arithmetic is on integer paise.
 *
 * Business rules used (from existing configuration, nothing invented):
 *  - price = products.price, MRP = products.mrp, discount = MRP - price
 *  - shipping = 0 when the subtotal is 0 or >= the free-shipping threshold, else the flat fee. Both come from business settings
 *    (shipping.free_above / shipping.flat_fee), falling back to vistaro.shipping.* config (Rs 1999 / Rs 99) when no valid row exists
 *  - no tax line (prices are treated as tax-inclusive), no coupons
 */
final class PricingService
{
    public function __construct(private readonly ?BusinessSettingsService $settings = null)   // optional: `new PricingService()` still works
    {
    }

    public const NEEDS_ATTENTION = ['product_unavailable', 'out_of_stock', 'insufficient_stock'];

    /**
     * @param Collection<int,CartItem> $items with productSize.product.{category,subCategory,primaryImage} and color loaded
     * @return array{items:list<array<string,mixed>>,totals:array<string,mixed>,issues:list<array<string,mixed>>,can_checkout:bool}
     */
    public function build(Collection $items): array
    {
        // Lines of the same size in different colours draw on ONE stock row, so stock is checked against their combined quantity.
        $demand = $items->groupBy('product_size_id')->map(fn ($g) => (int) $g->sum('quantity'));

        $lines = [];
        $issues = [];
        $subtotal = $mrpTotal = $units = 0;

        foreach ($items as $item) {
            $size = $item->productSize;
            $product = $size->product;
            $unit = Money::toMinor($product->price);
            $mrp = Money::toMinor($product->mrp);
            $issue = $this->issue($item, $demand[$item->product_size_id]);

            $subtotal += $unit * $item->quantity;
            $mrpTotal += $mrp * $item->quantity;
            $units += $item->quantity;

            if ($issue) {
                $issues[] = ['item_id' => $item->id] + $issue;
            }

            $lines[] = [
                'id' => $item->id,
                'product' => ['id' => $product->id, 'name' => $product->name, 'slug' => $product->slug, 'sku' => $size->sku ?? $product->sku],
                'size' => $size->size->value,
                'color' => $item->color ? ['id' => $item->color->id, 'name' => $item->color->name, 'hex' => $item->color->hex] : null,
                'image' => $product->primaryImage ? ImageUrl::resolve($product->primaryImage->path, $product->primaryImage->disk) : null,
                'unit_price' => Money::format($unit),
                'mrp' => Money::format($mrp),
                'discount_percent' => $product->discount_percent,
                'quantity' => $item->quantity,
                'line_subtotal' => Money::format($unit * $item->quantity),
                'available' => $issue === null,
                'issue' => $issue['code'] ?? null,
                'issue_message' => $issue['message'] ?? null,
                'stock_left' => StockDisplay::storefrontLeft($size->quantity),
            ];
        }

        $settings = $this->settings ?? app(BusinessSettingsService::class);
        $freeAbove = Money::toMinor($settings->freeShippingThreshold());
        $shipping = ($subtotal === 0 || $subtotal >= $freeAbove) ? 0 : Money::toMinor($settings->shippingFlatFee());

        return [
            'items' => $lines,
            'totals' => [
                'currency' => 'INR',
                'item_count' => $units,
                'subtotal' => Money::format($subtotal),
                'mrp_total' => Money::format($mrpTotal),
                'discount_total' => Money::format($mrpTotal - $subtotal),
                'shipping_fee' => Money::format($shipping),
                'total' => Money::format($subtotal + $shipping),
                'free_shipping_above' => Money::format($freeAbove),
                'amount_for_free_shipping' => Money::format($subtotal === 0 ? 0 : max(0, $freeAbove - $subtotal)),
            ],
            'issues' => $issues,
            'can_checkout' => $lines !== [] && $issues === [],
        ];
    }

    /** @return array{code:string,message:string}|null */
    private function issue(CartItem $item, int $demand): ?array
    {
        $size = $item->productSize;
        $p = $size->product;

        $visible = $p->status === ProductStatus::Active && $p->is_available
            && $p->subCategory?->is_active && $p->category?->is_active;

        if (! $visible) {
            return ['code' => 'product_unavailable', 'message' => 'This item is no longer available.'];
        }
        if ($size->quantity <= 0) {
            return ['code' => 'out_of_stock', 'message' => "Size {$size->size->value} is out of stock."];
        }
        if ($size->quantity < $demand) {
            return ['code' => 'insufficient_stock', 'message' => "Only {$size->quantity} left in size {$size->size->value}."];
        }

        return null;
    }
}
