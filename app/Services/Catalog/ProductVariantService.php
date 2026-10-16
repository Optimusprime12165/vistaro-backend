<?php

namespace App\Services\Catalog;

use App\Enums\InventoryLocation;
use App\Enums\InventoryMovementType;
use App\Exceptions\ConflictException;
use App\Models\Admin;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Services\Inventory\InventoryService;
use App\Support\SizeCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Colours (a product option, no stock of their own) and sizes (the stock-keeping unit, ONE shared quantity each) of a product. */
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
     * Create missing sizes and optionally set their shared quantity (absolute) and enabled flag.
     * Newly created sizes record an "initial" movement, existing ones an "adjustment".
     * Enabling / disabling never changes the quantity.
     *
     * @param list<array{size:string,quantity?:int,is_enabled?:bool}> $rows
     */
    public function upsertSizes(Product $product, array $rows, ?Admin $actor = null, ?string $note = null): Collection
    {
        return DB::transaction(function () use ($product, $rows, $actor, $note) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            foreach ($rows as $row) {
                $code = SizeCode::normalize($row['size']);
                $ps = ProductSize::query()->where(['product_id' => $product->id, 'size' => $code])->first();
                $created = $ps === null;

                $ps ??= ProductSize::query()->create([
                    'product_id' => $product->id, 'size' => $code, 'sku' => "{$product->sku}-" . str_replace(' ', '-', $code),
                    'quantity' => 0, 'reserved' => 0, 'is_enabled' => true,
                ]);

                if (isset($row['quantity'])) {
                    $this->inventory->setQuantity($ps->id, (int) $row['quantity'], $actor, $note, $created ? InventoryMovementType::Initial : InventoryMovementType::Adjustment);
                }
                if (array_key_exists('is_enabled', $row) && $row['is_enabled'] !== null && (bool) $row['is_enabled'] !== $ps->is_enabled) {
                    $ps->forceFill(['is_enabled' => (bool) $row['is_enabled']])->save();
                }
            }

            return ProductSize::query()->where('product_id', $product->id)->orderBy('id')->get();
        });
    }

    /**
     * Remove a size from a product.
     *  - refused (409) while ANY stock would silently disappear (quantity or reserved > 0),
     *  - sizes that appear on historical orders are DISABLED (kept, so order items and the ledger stay valid),
     *  - sizes that were never ordered are deleted.
     *
     * @return 'deleted'|'disabled'
     */
    public function removeSize(Product $product, string $size): string
    {
        $code = SizeCode::normalize($size);

        return DB::transaction(function () use ($product, $code) {
            $ps = ProductSize::query()->where(['product_id' => $product->id, 'size' => $code])->lockForUpdate()->firstOrFail();

            if ($ps->quantity > 0 || $ps->reserved > 0) {
                throw new ConflictException("Size {$code} still has stock (available {$ps->quantity}, reserved {$ps->reserved}). Set it to zero first.");
            }

            if (OrderItem::query()->where('product_size_id', $ps->id)->exists()) {
                $ps->forceFill(['is_enabled' => false])->save();

                return 'disabled';
            }

            $ps->delete();

            return 'deleted';
        });
    }

    /**
     * Absolute shared stock per size, all-or-nothing. Unknown sizes are rejected before anything changes,
     * rows are processed in id order so concurrent bulk edits cannot deadlock each other.
     *
     * @param array<string,int|string> $sizes size => quantity
     */
    public function setStock(Product $product, array $sizes, ?Admin $actor = null, ?string $note = null, InventoryLocation $source = InventoryLocation::Offline): void
    {
        $normalised = [];
        foreach ($sizes as $size => $qty) {
            $normalised[SizeCode::normalize((string) $size)] = (int) $qty;
        }
        $rows = $this->resolveSizes($product, array_keys($normalised));

        DB::transaction(function () use ($normalised, $rows, $actor, $note, $source) {
            foreach ($rows as $size => $ps) {
                $this->inventory->setQuantity($ps->id, $normalised[$size], $actor, $note, InventoryMovementType::Adjustment, $source);
            }
        });
    }

    /** Relative manual change of the shared quantity (e.g. -2 after two pieces were sold in the shop). */
    public function adjustStock(Product $product, string $size, int $delta, ?Admin $actor = null, ?string $note = null, InventoryLocation $source = InventoryLocation::Offline): ProductSize
    {
        $code = SizeCode::normalize($size);
        $ps = $this->resolveSizes($product, [$code])[$code];

        return $this->inventory->adjustQuantity($ps->id, $delta, InventoryMovementType::Adjustment, $actor, $note, null, null, $source);
    }

    /** @return array<string,ProductSize> keyed by normalised size, ordered by id */
    private function resolveSizes(Product $product, array $sizes): array
    {
        $found = ProductSize::query()->where('product_id', $product->id)->whereIn('size', $sizes)->orderBy('id')->get()->keyBy('size');
        $missing = array_values(array_diff($sizes, $found->keys()->all()));

        if ($missing !== []) {
            throw ValidationException::withMessages(collect($missing)->mapWithKeys(
                fn ($s) => ["sizes.{$s}" => ["Size {$s} is not defined for this product. Add it first."]],
            )->all());
        }

        return $found->all();
    }
}
