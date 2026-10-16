<?php

namespace App\Http\Resources;

use App\Support\ImageUrl;
use App\Support\SizeCode;
use App\Support\StockDisplay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Admin view of a product with REAL (shared) stock numbers. Relations that are loaded are shown; nothing is queried here.
 *
 * `stock` is ONE block built from `sizes` (product_sizes): the same quantities serve online orders and the shop.
 *  - sizes[].quantity   = shared sellable units of that size, sizes[].reserved = held by unfulfilled online orders
 *  - sizes[].is_enabled = size can be bought on the website (a disabled size keeps its quantity)
 *  - total              = DERIVED sum of all sizes' quantities (display only, never stored)
 *  - sellable_total     = DERIVED sum over enabled sizes; `status` / out_of_stock are judged on enabled sizes
 * `channel` (online | offline | both | none) only says where the product is visible.
 *
 * @mixin \App\Models\Product
 */
class AdminProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'sku' => $r->sku,
            'description' => $r->description,
            'price' => $r->price,
            'mrp' => $r->mrp,
            'discount_percent' => $r->discount_percent,
            'currency' => $r->currency,
            'attributes' => (object) ($r->attributes ?? []),
            'status' => $r->status->value,
            'is_active' => $r->status === \App\Enums\ProductStatus::Active,
            'channel' => $r->channel(),
            'is_available' => $r->is_available,
            'is_offline_available' => $r->is_offline_available,
            'is_bestseller' => $r->is_bestseller,
            'is_new_arrival' => $r->is_new_arrival,
            'category' => $this->whenLoaded('category', fn () => ['id' => $r->category->id, 'name' => $r->category->name, 'slug' => $r->category->slug]),
            'sub_category' => $this->whenLoaded('subCategory', fn () => ['id' => $r->subCategory->id, 'name' => $r->subCategory->name, 'slug' => $r->subCategory->slug]),
            'category_id' => $r->category_id,
            'sub_category_id' => $r->sub_category_id,
            'image' => $this->whenLoaded('primaryImage', fn () => $r->primaryImage ? ImageUrl::resolve($r->primaryImage->path, $r->primaryImage->disk) : null),
            'images' => $this->whenLoaded('images', fn () => $r->images->map(fn ($i) => [
                'id' => $i->id, 'url' => ImageUrl::resolve($i->path, $i->disk), 'alt' => $i->alt, 'sort_order' => $i->sort_order, 'is_primary' => $i->is_primary,
            ])->values()),
            'colors' => $this->whenLoaded('colors', fn () => $r->colors->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'hex' => $c->hex])->values()),
            'stock' => $this->whenLoaded('sizes', fn () => self::block($r->sizes)),
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{total:int,sellable_total:int,reserved:int,status:string,low_stock:bool,out_of_stock:bool,sizes:list<array<string,mixed>>} */
    public static function block(Collection $rows): array
    {
        $rows = $rows->sortBy(fn ($s) => SizeCode::sortKey($s->size))->values();
        $enabled = $rows->where('is_enabled', true);
        $sellable = (int) $enabled->sum('quantity');
        $out = $sellable === 0;
        $low = ! $out && $enabled->contains(fn ($s) => StockDisplay::adminStatus($s->quantity) !== 'ok');

        return [
            'total' => (int) $rows->sum('quantity'),
            'sellable_total' => $sellable,
            'reserved' => (int) $rows->sum('reserved'),
            'status' => $out ? 'out' : ($low ? 'low' : 'ok'),
            'low_stock' => $low,
            'out_of_stock' => $out,
            'sizes' => $rows->map(fn ($s) => [
                'id' => $s->id,
                'size' => $s->size,
                'quantity' => $s->quantity,
                'reserved' => $s->reserved,
                'is_enabled' => (bool) $s->is_enabled,
                'status' => StockDisplay::adminStatus($s->quantity),
            ])->all(),
        ];
    }
}
