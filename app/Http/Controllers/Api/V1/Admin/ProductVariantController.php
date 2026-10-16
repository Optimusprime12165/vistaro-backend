<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ColorsRequest;
use App\Http\Requests\Admin\SizesRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends ApiController
{
    public function __construct(private readonly ProductVariantService $variants)
    {
    }

    public function colors(ColorsRequest $request, Product $product): JsonResponse
    {
        $this->variants->syncColors($product, $request->validated('colors'));

        return $this->ok(AdminProductResource::make($product->load('colors'))->resolve($request), 'Colours updated.');
    }

    public function sizes(SizesRequest $request, Product $product): JsonResponse
    {
        $this->variants->upsertSizes($product, $request->validated('sizes'), $request->user(), $request->validated('note'));

        return $this->ok(AdminProductResource::make($product->load(['sizes']))->resolve($request), 'Sizes updated.');
    }

    public function removeSize(Request $request, Product $product, string $size): JsonResponse
    {
        $result = $this->variants->removeSize($product, rawurldecode($size));
        $product->load(['sizes']);

        return $this->ok(AdminProductResource::make($product)->resolve($request) + ['result' => $result], $result === 'disabled'
            ? 'This size is used by past orders, so it was disabled instead of deleted.'
            : 'Size removed.');
    }
}
