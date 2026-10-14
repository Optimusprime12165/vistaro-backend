<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Http\Requests\Admin\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use App\Services\Catalog\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends ApiController
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    public function index(CatalogIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->categories->index($request->toQuery()), CategoryResource::class);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        return $this->created(CategoryResource::make($this->categories->store($request->validated()))->resolve($request), 'Category created.');
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        return $this->ok(CategoryResource::make($this->categories->show($category->id))->resolve($request));
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        return $this->ok(CategoryResource::make($this->categories->update($category, $request->validated()))->resolve($request), 'Category updated.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->categories->destroy($category);

        return $this->ok(null, 'Category deleted.');
    }
}
