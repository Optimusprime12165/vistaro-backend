<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Customer\OrderIndexRequest;
use App\Http\Requests\Customer\OrderReasonRequest;
use App\Http\Requests\Customer\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\Commerce\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    public function index(OrderIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->orders->list($request->user(), $request->toQuery()), OrderResource::class);
    }

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $result = $this->orders->place($request->user(), $request->address(), $request->method(), $request->key());

        return response()->json(['success' => true] + $result['body'], $result['status'], $result['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ok(OrderResource::make($this->orders->show($request->user(), $id))->resolve($request));
    }

    public function cancel(OrderReasonRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->cancel($request->user(), $id, $request->reason());

        return $this->ok(OrderResource::make($order)->resolve($request), 'Order cancelled.');
    }

    public function return(OrderReasonRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->requestReturn($request->user(), $id, (string) $request->reason());

        return $this->ok(OrderResource::make($order)->resolve($request), 'Return requested.');
    }
}
