<?php

namespace App\Services\Catalog;

use App\Contracts\ImageStorageInterface;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Uploads go through ImageStorageInterface (local disk in development). Exactly one image per product is primary. */
final class ProductImageService
{
    public function __construct(private readonly ImageStorageInterface $storage)
    {
    }

    /**
     * @param list<\Illuminate\Http\UploadedFile> $files
     * @param list<string> $urls https links stored as-is (never fetched by the server)
     * @return Collection<int,ProductImage> the created images
     */
    public function add(Product $product, array $files, array $urls, ?string $alt = null): Collection
    {
        $stored = [];

        try {
            return DB::transaction(function () use ($product, $files, $urls, $alt, &$stored) {
                Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();   // serialises concurrent uploads

                $existing = ProductImage::query()->where('product_id', $product->id);
                $max = (int) config('vistaro.images.max_per_product');

                if ($existing->count() + count($files) + count($urls) > $max) {
                    throw new ApiException("A product can have at most {$max} images.", 422, ['images' => ["At most {$max} images per product."]]);
                }

                $next = (int) ($existing->max('sort_order') ?? -1) + 1;
                $hasPrimary = (clone $existing)->where('is_primary', true)->exists();
                $created = collect();

                foreach ($files as $file) {
                    $s = $this->storage->store($file, "products/{$product->id}");
                    $stored[] = $s;
                    $created->push($this->make($product, $s->disk, $s->path, $alt, $next++, ! $hasPrimary && $created->isEmpty()));
                }
                foreach ($urls as $url) {
                    $created->push($this->make($product, 'external', $url, $alt, $next++, ! $hasPrimary && $created->isEmpty()));
                }

                return $created;
            });
        } catch (Throwable $e) {
            foreach ($stored as $s) {                       // do not leave orphaned files behind when the transaction failed
                $this->storage->delete($s->disk, $s->path);
            }
            throw $e;
        }
    }

    public function delete(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $wasPrimary = $image->is_primary;
            [$disk, $path] = [$image->disk, $image->path];
            $image->delete();

            if ($wasPrimary) {                               // promote the first remaining image
                ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->limit(1)->update(['is_primary' => true]);
            }
            if ($disk !== 'external') {
                DB::afterCommit(fn () => $this->storage->delete($disk, $path));
            }
        });
    }

    /** @param list<int> $ids must be exactly the product's image ids, in the desired order */
    public function reorder(Product $product, array $ids): Collection
    {
        return DB::transaction(function () use ($product, $ids) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $current = ProductImage::query()->where('product_id', $product->id)->pluck('id')->all();
            if (count($ids) !== count($current) || array_diff($ids, $current) !== [] || array_diff($current, $ids) !== []) {
                throw new ApiException('The order must list every image of this product exactly once.', 422, ['order' => ['Provide all image ids of this product.']]);
            }

            foreach (array_values($ids) as $position => $id) {
                ProductImage::query()->whereKey($id)->update(['sort_order' => $position]);
            }

            return ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
        });
    }

    public function makePrimary(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            ProductImage::query()->where('product_id', $product->id)->update(['is_primary' => false]);
            ProductImage::query()->whereKey($image->id)->update(['is_primary' => true]);
        });
    }

    private function make(Product $product, string $disk, string $path, ?string $alt, int $order, bool $primary): ProductImage
    {
        return ProductImage::query()->create([
            'product_id' => $product->id, 'disk' => $disk, 'path' => $path, 'alt' => $alt ?? $product->name,
            'sort_order' => $order, 'is_primary' => $primary,
        ]);
    }
}
