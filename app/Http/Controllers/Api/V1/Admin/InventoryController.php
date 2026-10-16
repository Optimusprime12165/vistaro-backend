<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryLocation;
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

    /**
     * GET /admin/inventory?location=online|offline : the two Stocks tabs. online = Online + Both products, offline = Offline + Both
     * products; a Both product shows the SAME shared quantities in both. Size-wise stock, derived totals, low/out flags.
     */
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
        $this->variants->setStock($product, $v['sizes'], $request->user(), $v['note'], InventoryLocation::from($v['location'] ?? 'offline'));

        return $this->ok(AdminProductResource::make($product->load(['sizes']))->resolve($request), 'Stock updated.');
    }

    public function adjust(StockAdjustRequest $request, Product $product): JsonResponse
    {
        $v = $request->validated();
        $this->variants->adjustStock($product, $v['size'], (int) $v['delta'], $request->user(), $v['note'], InventoryLocation::from($v['location'] ?? 'offline'));

        return $this->ok(AdminProductResource::make($product->load(['sizes']))->resolve($request), 'Stock adjusted.');
    }
}
