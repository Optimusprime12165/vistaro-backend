<?php

namespace App\Services\Catalog;

use App\Enums\InventoryMovementType;
use App\Enums\Size;
use App\Exceptions\ConflictException;
use App\Models\Admin;
use App\Models\OfflineStock;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Colours (a product option, no stock of their own) and sizes (the stock-keeping unit) of a product. */
final class ProductVariantService
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /** Replace the colour list: matches by name (updates hex/order), creates new ones, removes the rest. */
    public function syncColors(Product $product, array $rows): Collection
    {
        return DB::transaction(function () use ($product, $rows) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();   // serialise concurrent edits of one product
            $existing = ProductColor::query()->where('product_id', $product->id)->get()->keyBy(fn ($c) => mb_strtolower($c->name));
            $keep = [];

            foreach (array_values($rows) as $i => $row) {
                $color = $existing->get(mb_strtolower($row['name']));
                $values = ['name' => $row['name'], 'hex' => strtoupper($row['hex']), 'sort_order' => $i];
                $color ? $color->update($values) : $color = ProductColor::query()->create($values + ['product_id' => $product->id]);
                $keep[] = $color->id;
            }

            ProductColor::query()->where('product_id', $product->id)->whereNotIn('id', $keep)->delete();

            return ProductColor::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
        });
    }

    /**
     * Create missing sizes and optionally set their online / offline quantity (absolute).
     * Newly created sizes record an "initial" movement, existing ones an "adjustment".
     *
     * @param list<array{size:string,quantity?:int,offline_quantity?:int}> $rows
     */
    public function upsertSizes(Product $product, array $rows, ?Admin $actor = null, ?string $note = null): Collection
    {
        return DB::transaction(function () use ($product, $rows, $actor, $note) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            foreach ($rows as $row) {
                $size = Size::from($row['size']);
                $ps = ProductSize::query()->where(['product_id' => $product->id, 'size' => $size->value])->first();
                $created = $ps === null;

                $ps ??= ProductSize::query()->create([                         // the model observer also creates the offline row
                    'product_id' => $product->id, 'size' => $size, 'sku' => "{$product->sku}-{$size->value}", 'quantity' => 0, 'reserved' => 0,
                ]);

                $type = $created ? InventoryMovementType::Initial : InventoryMovementType::Adjustment;
                if (isset($row['quantity'])) {
                    $this->inventory->setOnline($ps->id, (int) $row['quantity'], $actor, $note, $type);
                }
                if (isset($row['offline_quantity'])) {
                    $this->inventory->setOffline($product->id, $size, (int) $row['offline_quantity'], $actor, $note, $type);
                }
            }

            return ProductSize::query()->where('product_id', $product->id)->orderBy('id')->get();
        });
    }

    /** A size can only be removed when no stock (online, reserved or in the shop) would silently disappear. */
    public function removeSize(Product $product, Size $size): void
    {
        DB::transaction(function () use ($product, $size) {
            $ps = ProductSize::query()->where(['product_id' => $product->id, 'size' => $size->value])->lockForUpdate()->firstOrFail();
            $offline = (int) OfflineStock::query()->where(['product_id' => $product->id, 'size' => $size->value])->value('quantity');

            if ($ps->quantity > 0 || $ps->reserved > 0 || $offline > 0) {
                throw new ConflictException("Size {$size->value} still has stock (online {$ps->quantity}, reserved {$ps->reserved}, shop {$offline}). Set it to zero first.");
            }

            $ps->delete();                                                  // offline row cascades
        });
    }

    /**
     * Absolute stock per size for one location, all-or-nothing. Unknown sizes are rejected before anything changes,
     * rows are processed in id order so concurrent bulk edits cannot deadlock each other.
     *
     * @param array<string,int|string> $sizes size => quantity
     */
    public function setStock(Product $product, string $location, array $sizes, ?Admin $actor = null, ?string $note = null): void
    {
        $rows = $this->resolveSizes($product, array_keys($sizes));

        DB::transaction(function () use ($product, $location, $sizes, $rows, $actor, $note) {
            foreach ($rows as $size => $ps) {
                $qty = (int) $sizes[$size];
                $location === 'online'
                    ? $this->inventory->setOnline($ps->id, $qty, $actor, $note)
                    : $this->inventory->setOffline($product->id, Size::from($size), $qty, $actor, $note);
            }
        });
    }

    public function adjustStock(Product $product, string $location, Size $size, int $delta, ?Admin $actor = null, ?string $note = null): void
    {
        $ps = $this->resolveSizes($product, [$size->value])[$size->value];

        $location === 'online'
            ? $this->inventory->adjustOnline($ps->id, $delta, InventoryMovementType::Adjustment, $actor, $note)
            : $this->inventory->adjustOffline($product->id, $size, $delta, $actor, $note);
    }

    /** @return array<string,ProductSize> keyed by size, ordered by id */
    private function resolveSizes(Product $product, array $sizes): array
    {
        $found = ProductSize::query()->where('product_id', $product->id)->whereIn('size', $sizes)->orderBy('id')->get()->keyBy(fn ($r) => $r->size->value);
        $missing = array_values(array_diff($sizes, $found->keys()->all()));

        if ($missing !== []) {
            throw ValidationException::withMessages(collect($missing)->mapWithKeys(
                fn ($s) => ["sizes.{$s}" => ["Size {$s} is not defined for this product. Add it first."]],
            )->all());
        }

        return $found->all();
    }
}
