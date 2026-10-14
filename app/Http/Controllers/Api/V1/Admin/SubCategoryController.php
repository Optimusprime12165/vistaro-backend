<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Http\Requests\Admin\SubCategoryRequest;
use App\Http\Resources\SubCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\SubCategory;
use App\Services\Catalog\SubCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubCategoryController extends ApiController
{
    public function __construct(private readonly SubCategoryService $subCategories)
    {
    }

    public function index(CatalogIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->subCategories->index($request->toQuery()), SubCategoryResource::class);
    }

    public function store(SubCategoryRequest $request): JsonResponse
    {
        $model = $this->subCategories->store($request->validated())->load('category:id,name,slug');

        return $this->created(SubCategoryResource::make($model)->resolve($request), 'Sub-category created.');
    }

    public function show(Request $request, SubCategory $subCategory): JsonResponse
    {
        return $this->ok(SubCategoryResource::make($this->subCategories->show($subCategory->id))->resolve($request));
    }

    public function update(SubCategoryRequest $request, SubCategory $subCategory): JsonResponse
    {
        $model = $this->subCategories->update($subCategory, $request->validated())->load('category:id,name,slug');

        return $this->ok(SubCategoryResource::make($model)->resolve($request), 'Sub-category updated.');
    }

    public function destroy(SubCategory $subCategory): JsonResponse
    {
        $this->subCategories->destroy($subCategory);

        return $this->ok(null, 'Sub-category deleted.');
    }
}
