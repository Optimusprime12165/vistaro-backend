<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Catalog\FacetsRequest;
use App\Http\Requests\Catalog\ProductIndexRequest;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Services\Catalog\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function __construct(private readonly StorefrontCatalogService $catalog)
    {
    }

    public function index(ProductIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->catalog->list($request->toQuery()), ProductResource::class);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $product = $this->catalog->find($slug) ?? throw new ApiException('Resource not found.', 404);

        return $this->ok(ProductDetailResource::make($product)->resolve($request));
    }

    public function related(Request $request, string $slug): JsonResponse
    {
        $product = $this->catalog->find($slug) ?? throw new ApiException('Resource not found.', 404);

        return $this->ok(ProductResource::collection($this->catalog->related($product))->resolve($request));
    }

    public function facets(FacetsRequest $request): JsonResponse
    {
        $v = $request->validated();

        return $this->ok($this->catalog->facets($v['category'] ?? null, $v['sub_category'] ?? null));
    }
}
