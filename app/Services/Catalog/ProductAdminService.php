<?php

namespace App\Services\Catalog;

use App\Abstracts\AbstractCrudService;
use App\Contracts\ImageStorageInterface;
use App\DTOs\ListQuery;
use App\Enums\ProductStatus;
use App\Models\Admin;
use App\Models\OfflineStock;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\SubCategory;
use App\Services\Catalog\Concerns\HandlesCatalogRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Admin product catalogue. CrudInterface supplies list/show plumbing; creation, update rules and deletion are specialised. */
final class ProductAdminService extends AbstractCrudService
{
    use HandlesCatalogRecords;

    /** Table the stock_status filter looks at: product_sizes (online) or offline_stocks (shop). */
    private string $stockTable = 'product_sizes';

    public function __construct(private readonly ProductVariantService $variants, private readonly ImageStorageInterface $storage)
    {
    }

    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function searchable(): array
    {
        return ['name', 'sku'];
    }

    protected function searchContains(): bool
    {
        return true;       // admin-only; swap for a FULLTEXT index if the catalogue grows to many thousands (Phase 9)
    }

    protected function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'price' => 'price', 'created_at' => 'created_at', 'stock' => 'stock_total'];
    }

    protected function filterable(): array
    {
        return [
            'status' => 'status',
            'category_id' => 'category_id',
            'sub_category_id' => 'sub_category_id',
            'is_available' => fn (Builder $q, $v) => $q->where('is_available', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
            'stock_status' => fn (Builder $q, $v) => $this->stockStatus($q, (string) $v),
        ];
    }

    protected function with(): array
    {
        return ['category:id,name,slug', 'subCategory:id,name,slug', 'primaryImage', 'sizes'];
    }

    protected function newQuery(): Builder
    {
        return parent::newQuery()->withSum('sizes as stock_total', 'quantity')->withMin('sizes as stock_min', 'quantity');
    }

    // ------------------------------------------------------------------ create

    /**
     * @param array<string,mixed> $data validated
     * @return Product with colours / sizes / images loaded
     */
    public function create(array $data, ?Admin $actor = null): Product
    {
        return DB::transaction(function () use ($data, $actor) {
            $colors = Arr::pull($data, 'colors', []);
            $sizes = Arr::pull($data, 'sizes', []);

            $data = $this->normalize($data);
            $data['slug'] = ! empty($data['slug']) ? $data['slug'] : $this->uniqueSlug($data['name'], Product::class);
            $data['status'] ??= ProductStatus::Active->value;

            $product = Product::query()->create($data);

            if ($colors !== []) {
                $this->variants->syncColors($product, $colors);
            }
            if ($sizes !== []) {
                $this->variants->upsertSizes($product, $sizes, $actor, 'Initial stock');
            }

            return $product->load(['category:id,name,slug', 'subCategory:id,name,slug', 'images', 'colors', 'sizes', 'offlineStocks']);
        });
    }

    public function store(array $data): Model
    {
        return $this->create($data);
    }

    protected function beforeUpdate(Model $model, array $data): array
    {
        if (array_key_exists('slug', $data) && empty($data['slug'])) {
            unset($data['slug']);
        }

        return $this->normalize($data);
    }

    // ------------------------------------------------------------------ delete

    /** Products that were ever sold are archived (history must keep pointing at them); the rest are removed with their stock rows and files. */
    public function archiveOrDelete(Product $product): string
    {
        return DB::transaction(function () use ($product) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (OrderItem::query()->where('product_id', $product->id)->exists()) {
                $product->forceFill([
                    'status' => ProductStatus::Archived, 'is_available' => false, 'is_offline_available' => false,
                ])->save();

                return 'archived';
            }

            $files = $product->images()->where('disk', '!=', 'external')->get(['disk', 'path']);
            $product->delete();                      // sizes, offline stock, colours, images cascade
            DB::afterCommit(fn () => $files->each(fn ($f) => $this->storage->delete($f->disk, $f->path)));

            return 'deleted';
        });
    }

    public function destroy(int|string|Model $target): void
    {
        $this->archiveOrDelete($this->resolve($target));
    }

    // --------------------------------------------------------------- inventory

    /** Size-wise stock table for one location (products only; archived ones are excluded). */
    public function inventory(ListQuery $query, string $location): LengthAwarePaginator
    {
        $offline = $location === 'offline';
        $rel = $offline ? 'offlineStocks' : 'sizes';
        $this->stockTable = $offline ? 'offline_stocks' : 'product_sizes';

        $builder = Product::query()->where('status', '!=', ProductStatus::Archived->value)
            ->with(['subCategory:id,name,slug', 'primaryImage', $rel])
            ->withSum("{$rel} as stock_total", 'quantity')->withMin("{$rel} as stock_min", 'quantity');

        $this->applySearch($builder, $query->search);
        $this->applyFilters($builder, $query->filters);
        $this->applySort($builder, $query->sort, $query->direction);

        return $builder->paginate($query->perPage, ['*'], 'page', $query->page);
    }

    /** Size-level rows below the low-stock threshold (zero included), emptiest first. */
    public function lowStock(ListQuery $query, string $location): Paginator
    {
        $model = $location === 'offline' ? OfflineStock::class : ProductSize::class;

        return $model::query()
            ->with('product:id,name,sku,slug')
            ->where('quantity', '<', (int) config('vistaro.stock.low_threshold'))
            ->whereIn('product_id', Product::query()->where('status', '!=', ProductStatus::Archived->value)->select('id'))
            ->orderBy('quantity')->orderBy('id')
            ->paginate($query->perPage, ['*'], 'page', $query->page);
    }

    // ---------------------------------------------------------------- internals

    /** Keeps category_id consistent with the sub-category, and attributes as a clean string map. */
    private function normalize(array $data): array
    {
        if (isset($data['sub_category_id'])) {
            $data['category_id'] = SubCategory::query()->whereKey($data['sub_category_id'])->value('category_id');
        }

        if (array_key_exists('attributes', $data)) {
            $clean = [];
            foreach ((array) $data['attributes'] as $key => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $clean[mb_strtolower((string) $key)] = $value;
                }
            }
            $data['attributes'] = $clean === [] ? null : $clean;
        }

        unset($data['discount_percent']);        // always derived from price/MRP by the model

        return $data;
    }

    private function stockStatus(Builder $q, string $status): void
    {
        $t = $this->stockTable;
        $low = (int) config('vistaro.stock.low_threshold');
        $rows = fn () => fn ($s) => $s->from($t)->whereColumn("{$t}.product_id", 'products.id');

        match ($status) {
            // nothing in stock in any size (or no sizes at all)
            'out' => $q->whereNotExists(fn ($s) => $s->from($t)->whereColumn("{$t}.product_id", 'products.id')->where("{$t}.quantity", '>', 0)),
            // something is in stock, but at least one size has fewer than the threshold (zero counts)
            'low' => $q->whereExists(fn ($s) => $s->from($t)->whereColumn("{$t}.product_id", 'products.id')->where("{$t}.quantity", '>', 0))
                ->whereExists(fn ($s) => $s->from($t)->whereColumn("{$t}.product_id", 'products.id')->where("{$t}.quantity", '<', $low)),
            // has sizes and every one of them is at or above the threshold
            default => $q->whereExists($rows())
                ->whereNotExists(fn ($s) => $s->from($t)->whereColumn("{$t}.product_id", 'products.id')->where("{$t}.quantity", '<', $low)),
        };
    }
}
