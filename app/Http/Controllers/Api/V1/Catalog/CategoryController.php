<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\CategoryResource;
use App\Services\Catalog\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends ApiController
{
    public function __construct(private readonly StorefrontCatalogService $catalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ok(CategoryResource::collection($this->catalog->categories())->resolve($request));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $category = $this->catalog->category($slug) ?? throw new ApiException('Resource not found.', 404);

        return $this->ok(CategoryResource::make($category)->resolve($request));
    }
}
