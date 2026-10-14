<?php

namespace App\Http\Resources;

use App\Enums\Size;
use App\Support\ImageUrl;
use App\Support\StockDisplay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Admin view of a product with REAL stock numbers. Relations that are loaded are shown; nothing is queried here.
 * stock.online: from `sizes` (quantity + reserved); stock.offline: from `offlineStocks`.
 * Status per size: out (0) | low (< 5) | ok.  Product: out when the total is 0, low when any size is below 5.
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
            'stock' => array_filter([
                'online' => $r->relationLoaded('sizes') ? self::block($r->sizes, true) : null,
                'offline' => $r->relationLoaded('offlineStocks') ? self::block($r->offlineStocks, false) : null,
            ], fn ($b) => $b !== null),
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{total:int,reserved?:int,status:string,low_stock:bool,out_of_stock:bool,sizes:list<array<string,mixed>>} */
    public static function block(Collection $rows, bool $withReserved): array
    {
        $rows = $rows->sortBy(fn ($s) => ($s->size instanceof Size ? $s->size : Size::from($s->size))->order())->values();
        $total = (int) $rows->sum('quantity');
        $out = $total === 0;
        $low = ! $out && $rows->contains(fn ($s) => StockDisplay::adminStatus($s->quantity) !== 'ok');

        return array_filter([
            'total' => $total,
            'reserved' => $withReserved ? (int) $rows->sum('reserved') : null,
            'status' => $out ? 'out' : ($low ? 'low' : 'ok'),
            'low_stock' => $low,
            'out_of_stock' => $out,
            'sizes' => $rows->map(fn ($s) => array_filter([
                'size' => $s->size instanceof Size ? $s->size->value : $s->size,
                'quantity' => $s->quantity,
                'reserved' => $withReserved ? $s->reserved : null,
                'status' => StockDisplay::adminStatus($s->quantity),
            ], fn ($v) => $v !== null))->all(),
        ], fn ($v) => $v !== null);
    }
}
