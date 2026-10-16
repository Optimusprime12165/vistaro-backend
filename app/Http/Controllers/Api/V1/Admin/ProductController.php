<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\AdminProductIndexRequest;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\AdminProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\Catalog\ProductAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    private const FULL = ['category:id,name,slug', 'subCategory:id,name,slug', 'images', 'colors', 'sizes'];

    public function __construct(private readonly ProductAdminService $products)
    {
    }

    public function index(AdminProductIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->products->index($request->toQuery()), AdminProductResource::class);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->user());

        return $this->created(AdminProductResource::make($product)->resolve($request), 'Product created.');
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return $this->ok(AdminProductResource::make($product->load(self::FULL))->resolve($request));
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->products->update($product, $request->validated())->load(self::FULL);

        return $this->ok(AdminProductResource::make($updated)->resolve($request), 'Product updated.');
    }

    /** Sold products are archived instead of deleted; the response says which happened. */
    public function destroy(Product $product): JsonResponse
    {
        $result = $this->products->archiveOrDelete($product);

        return $this->ok(['result' => $result], $result === 'archived'
            ? 'This product has order history, so it was archived instead of deleted.'
            : 'Product deleted.');
    }
}
