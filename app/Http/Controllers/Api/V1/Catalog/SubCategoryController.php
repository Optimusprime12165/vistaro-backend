<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\SubCategoryResource;
use App\Services\Catalog\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubCategoryController extends ApiController
{
    public function __construct(private readonly StorefrontCatalogService $catalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['category' => ['sometimes', 'string', 'max:140']]);

        return $this->ok(SubCategoryResource::collection($this->catalog->subCategories($v['category'] ?? null))->resolve($request));
    }
}
