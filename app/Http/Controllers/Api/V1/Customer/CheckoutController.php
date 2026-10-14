<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Customer\CheckoutPreviewRequest;
use App\Services\Commerce\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends ApiController
{
    public function __construct(private readonly CheckoutService $checkout)
    {
    }

    public function preview(CheckoutPreviewRequest $request): JsonResponse
    {
        return $this->ok($this->checkout->preview($request->user(), $request->address(), $request->method()));
    }
}
