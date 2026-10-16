<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Customer\CartItemRequest;
use App\Services\Commerce\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends ApiController
{
    public function __construct(private readonly CartService $cart)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->cart->view($request->user()));
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->ok($this->cart->clear($request->user()), 'Cart cleared.');
    }

    public function addItem(CartItemRequest $request): JsonResponse
    {
        $v = $request->validated();
        $cart = $this->cart->add($request->user(), (int) $v['product_id'], $v['size'], isset($v['color_id']) ? (int) $v['color_id'] : null, (int) $v['quantity']);

        return $this->created($cart, 'Added to cart.');
    }

    public function updateItem(CartItemRequest $request, int $id): JsonResponse
    {
        return $this->ok($this->cart->update($request->user(), $id, $request->validated()), 'Cart updated.');
    }

    public function removeItem(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->cart->remove($request->user(), $id), 'Item removed.');
    }
}
