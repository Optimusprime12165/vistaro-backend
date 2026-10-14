<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Size;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\InventoryIndexRequest;
use App\Http\Requests\Admin\StockAdjustRequest;
use App\Http\Requests\Admin\StockSetRequest;
use App\Http\Resources\AdminProductResource;
use App\Http\Resources\LowStockRowResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\Catalog\ProductAdminService;
use App\Services\Catalog\ProductVariantService;
use Illuminate\Http\JsonResponse;

class InventoryController extends ApiController
{
    public function __construct(private readonly ProductAdminService $products, private readonly ProductVariantService $variants)
    {
    }

    /** GET /admin/inventory?location=online|offline : size-wise stock per product, totals, low/out flags. */
    public function index(InventoryIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->products->inventory($request->toQuery(), $request->location()), AdminProductResource::class);
    }

    /** GET /admin/inventory/low-stock?location=... : every size below the threshold. */
    public function lowStock(InventoryIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->products->lowStock($request->toQuery(), $request->location()), LowStockRowResource::class);
    }

    public function set(StockSetRequest $request, Product $product): JsonResponse
    {
        $v = $request->validated();
        $this->variants->setStock($product, $v['location'], $v['sizes'], $request->user(), $v['note'] ?? null);

        return $this->ok(AdminProductResource::make($product->load(['sizes', 'offlineStocks']))->resolve($request), 'Stock updated.');
    }

    public function adjust(StockAdjustRequest $request, Product $product): JsonResponse
    {
        $v = $request->validated();
        $this->variants->adjustStock($product, $v['location'], Size::from($v['size']), (int) $v['delta'], $request->user(), $v['note'] ?? null);

        return $this->ok(AdminProductResource::make($product->load(['sizes', 'offlineStocks']))->resolve($request), 'Stock adjusted.');
    }
}
