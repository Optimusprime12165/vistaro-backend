<?php

namespace App\Services\Catalog;

use App\DTOs\ListQuery;
use App\Support\SizeCode;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductSize;
use App\Models\SubCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

/**
 * Everything the PUBLIC storefront may see. A product is visible only when it is active, available for sale,
 * and both its sub-category and category are active. Drafts, archived and inactive data never leave this class.
 * All filtering, sorting and paging happens in SQL; only the requested page is loaded.
 */
final class StorefrontCatalogService
{
    private const LIST_COLUMNS = [
        'id', 'category_id', 'sub_category_id', 'name', 'slug', 'sku', 'price', 'mrp', 'discount_percent', 'currency',
        'attributes', 'is_bestseller', 'is_new_arrival',
    ];

    private const ATTRIBUTE_FILTERS = ['fit', 'fabric', 'pattern'];

    /** Base constraint shared by listing, detail, related, facets and cart/checkout purchasability checks. */
    public function visible(): Builder
    {
        return Product::query()->storefront()->whereIn(
            'sub_category_id',
            SubCategory::query()->where('is_active', true)
                ->whereIn('category_id', Category::query()->where('is_active', true)->select('id'))->select('id'),
        );
    }

    private function relations(): array
    {
        return [
            'category:id,name,slug',
            'subCategory:id,name,slug',
            'images:id,product_id,disk,path,alt,sort_order,is_primary',
            'colors:id,product_id,name,hex,sort_order',
            // only ENABLED sizes are ever shown or sold; quantity is the shared (online + shop) stock
            'sizes' => fn ($q) => $q->where('is_enabled', true)->select('id', 'product_id', 'size', 'quantity', 'is_enabled'),
        ];
    }

    public function list(ListQuery $query): LengthAwarePaginator
    {
        $q = $this->visible()->select(self::LIST_COLUMNS)->with($this->relations());
        $this->applyFilters($q, $query);
        $this->applySort($q, $query->sort);

        return $q->paginate($query->perPage, ['*'], 'page', $query->page);
    }

    public function find(string $slugOrId): ?Product
    {
        $q = $this->visible()->with($this->relations())->select([...self::LIST_COLUMNS, 'description']);

        return ctype_digit($slugOrId) ? $q->whereKey((int) $slugOrId)->first() : $q->where('slug', $slugOrId)->first();
    }

    public function related(Product $product): Collection
    {
        $limit = (int) config('vistaro.catalog.related_limit');
        $base = fn () => $this->visible()->select(self::LIST_COLUMNS)->with($this->relations())->whereKeyNot($product->id)
            ->orderByDesc('is_bestseller')->orderBy('id');

        $items = $base()->where('sub_category_id', $product->sub_category_id)->limit($limit)->get();

        if ($items->count() < $limit) {   // top up from the same category
            $items = $items->concat($base()->where('category_id', $product->category_id)
                ->where('sub_category_id', '!=', $product->sub_category_id)->limit($limit - $items->count())->get());
        }

        return $items;
    }

    /** @return array<string,mixed> filter options for the listing sidebar, scoped to the visible catalogue (optionally one category). */
    public function facets(?string $category, ?string $subCategory): array
    {
        $base = $this->visible();
        $this->scopeToCategory($base, $category, $subCategory);
        $ids = (clone $base)->select('products.id');

        $attributes = [];
        foreach (self::ATTRIBUTE_FILTERS as $key) {
            $attributes[$key . 's'] = (clone $base)->reorder()->select(["attributes->{$key} as value"])->selectRaw('count(*) as total')
                ->whereNotNull("attributes->{$key}")->groupBy('value')->orderBy('value')->get()
                ->map(fn ($r) => ['value' => (string) $r->value, 'count' => (int) $r->total])->all();
        }

        $colors = ProductColor::query()->whereIn('product_id', $ids)->select('name', 'hex')->selectRaw('count(*) as total')
            ->groupBy('name', 'hex')->orderBy('name')->get()
            ->map(fn ($r) => ['name' => $r->name, 'hex' => $r->hex, 'count' => (int) $r->total])->all();

        $sizes = ProductSize::query()->whereIn('product_id', $ids)->where('is_enabled', true)->where('quantity', '>', 0)->select('size')->selectRaw('count(*) as total')
            ->groupBy('size')->get()->sortBy(fn ($r) => SizeCode::sortKey($r->size))
            ->map(fn ($r) => ['size' => $r->size, 'count' => (int) $r->total])->values()->all();

        $price = (clone $base)->reorder()->selectRaw('min(price) as min_price, max(price) as max_price')->first();

        return $attributes + [
            'colors' => $colors,
            'sizes' => $sizes,
            'price' => ['min' => $price?->min_price, 'max' => $price?->max_price],
        ];
    }

    // --------------------------------------------------------------- categories

    /** @return Collection<int,Category> active categories with active sub-categories and storefront product counts */
    public function categories(): Collection
    {
        $count = fn ($q) => $q->storefront();

        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->withCount(['products as products_count' => $count])
            ->with(['subCategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->withCount(['products as products_count' => $count])])
            ->get();

        // Hand every sub-category its already-loaded parent so SubCategoryResource can expose the parent
        // relationship ({id, name, slug}) without issuing one extra query per sub-category (no N+1).
        $categories->each(fn (Category $c) => $c->subCategories->each(fn (SubCategory $s) => $s->setRelation('category', $c)));

        return $categories;
    }

    public function category(string $slug): ?Category
    {
        return $this->categories()->firstWhere('slug', $slug);
    }

    public function subCategories(?string $categorySlug): Collection
    {
        return SubCategory::query()->where('is_active', true)
            ->whereIn('category_id', Category::query()->where('is_active', true)->select('id'))
            ->when($categorySlug, fn ($q) => $q->whereIn('category_id', Category::query()->where('slug', $categorySlug)->select('id')))
            ->with('category:id,name,slug')
            ->withCount(['products as products_count' => fn ($q) => $q->storefront()])
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    // ---------------------------------------------------------------- internals

    private function applyFilters(Builder $q, ListQuery $query): void
    {
        $f = $query->filters;

        if (($term = trim((string) $query->search)) !== '') {
            $q->where('name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%');
        }

        $this->scopeToCategory($q, $f['category'] ?? null, $f['sub_category'] ?? null);

        if (isset($f['price_min'])) {
            $q->where('price', '>=', $f['price_min']);
        }
        if (isset($f['price_max'])) {
            $q->where('price', '<=', $f['price_max']);
        }

        if ($sizes = Arr::wrap($f['size'] ?? [])) {
            $q->whereExists(fn ($s) => $s->from('product_sizes')->whereColumn('product_sizes.product_id', 'products.id')
                ->whereIn('product_sizes.size', array_map([SizeCode::class, 'normalize'], $sizes))
                ->where('product_sizes.is_enabled', true)->where('product_sizes.quantity', '>', 0));
        }
        if ($colors = Arr::wrap($f['color'] ?? [])) {
            $q->whereExists(fn ($s) => $s->from('product_colors')->whereColumn('product_colors.product_id', 'products.id')->whereIn('product_colors.name', $colors));
        }
        foreach (self::ATTRIBUTE_FILTERS as $key) {
            if ($values = Arr::wrap($f[$key] ?? [])) {
                $q->whereIn("attributes->{$key}", $values);          // validated JSON attribute, no per-attribute column
            }
        }

        match ($f['badge'] ?? null) {
            'new' => $q->where('is_new_arrival', true),
            'best' => $q->where('is_bestseller', true),
            'sale' => $q->where('discount_percent', '>=', (int) config('vistaro.catalog.sale_min_discount')),   // derived from price/MRP, never client supplied
            default => null,
        };
    }

    private function scopeToCategory(Builder $q, ?string $category, ?string $subCategory): void
    {
        if ($category) {
            $q->whereIn('products.category_id', Category::query()->where('slug', $category)->select('id'));
        }
        if ($subCategory) {
            $q->whereIn('products.sub_category_id', SubCategory::query()->where('slug', $subCategory)->select('id'));
        }
    }

    /** Whitelisted sorts; the id tie-breaker keeps page boundaries stable. */
    private function applySort(Builder $q, ?string $sort): void
    {
        match ($sort) {
            'newest' => $q->orderByDesc('id'),
            'price_asc' => $q->orderBy('price')->orderBy('id'),
            'price_desc' => $q->orderByDesc('price')->orderBy('id'),
            'best' => $q->orderByDesc('is_bestseller')->orderBy('id'),
            default => $q->orderBy('id'),                       // featured
        };
    }
}
