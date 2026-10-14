<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ImageOrderRequest;
use App\Http\Requests\Admin\ImageUploadRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductImageController extends ApiController
{
    public function __construct(private readonly ProductImageService $images)
    {
    }

    public function store(ImageUploadRequest $request, Product $product): JsonResponse
    {
        $this->images->add($product, $request->file('images', []), $request->validated('urls', []), $request->validated('alt'));

        return $this->created($this->payload($request, $product), 'Images added.');
    }

    public function destroy(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->images->delete($product, $this->owned($product, $image));

        return $this->ok($this->payload($request, $product), 'Image deleted.');
    }

    public function reorder(ImageOrderRequest $request, Product $product): JsonResponse
    {
        $this->images->reorder($product, array_map('intval', $request->validated('order')));

        return $this->ok($this->payload($request, $product), 'Images reordered.');
    }

    public function primary(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->images->makePrimary($product, $this->owned($product, $image));

        return $this->ok($this->payload($request, $product), 'Primary image updated.');
    }

    /** An image id from another product is treated as not found, never silently accepted. */
    private function owned(Product $product, ProductImage $image): ProductImage
    {
        return $image->product_id === $product->id ? $image : throw new ApiException('Resource not found.', 404);
    }

    private function payload(Request $request, Product $product): array
    {
        return AdminProductResource::make($product->refresh()->load(['images']))->resolve($request);
    }
}
