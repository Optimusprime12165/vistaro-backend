<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Customer\GuestCartLinesRequest;
use App\Services\Commerce\CartService;
use Illuminate\Http\JsonResponse;

/** Public + stateless: prices a browser-held guest cart. Nothing is persisted and no user data is touched. */
class GuestCartController extends ApiController
{
    public function __construct(private readonly CartService $cart)
    {
    }

    public function preview(GuestCartLinesRequest $request): JsonResponse
    {
        return $this->ok($this->cart->previewGuest($request->lines()));
    }
}
