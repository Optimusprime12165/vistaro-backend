<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\UserResource;
use App\Services\Catalog\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends ApiController
{
    public function __construct(private readonly StorefrontCatalogService $catalog)
    {
    }

    /**
     * The customer's profile (unchanged fields) plus the public navigation tree, so the storefront can build
     * header/footer/filters from one request. `categories` is exactly what GET /categories returns (active
     * categories with their active sub-categories); guests use that public endpoint instead.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->ok(
            UserResource::make($request->user())->resolve($request)
            + ['categories' => CategoryResource::collection($this->catalog->categories())->resolve($request)],
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return $this->ok(UserResource::make($user->refresh())->resolve($request), 'Profile updated.');
    }
}
