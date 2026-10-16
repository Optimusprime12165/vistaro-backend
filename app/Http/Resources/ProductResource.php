<?php

namespace App\Http\Resources;

use App\Support\ImageUrl;
use App\Support\SizeCode;
use App\Support\StockDisplay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Storefront product card. Needs: category, subCategory, images, colors, sizes loaded (no queries are made here).
 * Stock rules: a size is `available` when quantity > 0; `left`/`label` ("8 left") appear only when 1..10 remain,
 * larger quantities are never exposed.
 *
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Sizes are always the ENABLED ones (the catalogue query filters them); a size with no stock stays listed but unavailable.
        $sizes = $this->sizes->where('is_enabled', true)->sortBy(fn ($s) => SizeCode::sortKey($s->size))->values();
        $inStock = $sizes->contains(fn ($s) => $s->quantity > 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => $this->price,
            'mrp' => $this->mrp,
            'discount_percent' => $this->discount_percent,
            'currency' => $this->currency,
            'badges' => [
                'new' => $this->is_new_arrival,
                'bestseller' => $this->is_bestseller,
                'sale' => $this->discount_percent >= (int) config('vistaro.catalog.sale_min_discount'),
            ],
            'attributes' => (object) ($this->resource->attributes ?? []),   // dynamic key/value features: render only what exists
            'category' => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug],
            'sub_category' => ['id' => $this->subCategory->id, 'name' => $this->subCategory->name, 'slug' => $this->subCategory->slug],
            'images' => $this->images->map(fn ($i) => [
                'id' => $i->id, 'url' => ImageUrl::resolve($i->path, $i->disk), 'alt' => $i->alt, 'is_primary' => $i->is_primary,
            ])->values(),
            'colors' => $this->colors->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'hex' => $c->hex])->values(),
            'sizes' => $sizes->map(fn ($s) => [
                'size' => $s->size,
                'available' => $s->quantity > 0,
                'left' => StockDisplay::storefrontLeft($s->quantity),
                'label' => StockDisplay::storefrontLabel($s->quantity),
            ])->values(),
            'in_stock' => $inStock,
            // Every size sold out: the product stays listed (it is NOT hidden) but cannot be bought: show "Out of Stock".
            'out_of_stock' => ! $inStock,
        ];
    }
}
