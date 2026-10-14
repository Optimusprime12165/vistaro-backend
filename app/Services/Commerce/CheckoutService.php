<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\Cart;
use App\Models\User;

/** Read-only summary shown before "Place order": same pricing and stock checks as order creation, nothing is reserved or written. */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly PricingService $pricing,
        private readonly PaymentGatewayInterface $gateway,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(User $user, Address $address, PaymentMethod $method): array
    {
        $this->assertMethodAvailable($method);

        $cartRow = Cart::query()->where('user_id', $user->id)->first();
        $priced = $this->pricing->build($this->cart->items($cartRow));

        if ($priced['items'] === []) {
            throw new ApiException('Your cart is empty.', 422, ['cart' => ['Your cart is empty.']]);
        }

        return [
            'address' => AddressResource::make($address)->resolve(request()),
            'payment' => $this->paymentInfo($method),
            'items' => $priced['items'],
            'totals' => $priced['totals'],
            'issues' => $priced['issues'],
            'can_place_order' => $priced['can_checkout'],
        ];
    }

    /** Whether this method can be used right now. COD always; UPI/card only through a gateway that supports it. */
    public function assertMethodAvailable(PaymentMethod $method): void
    {
        if ($method === PaymentMethod::Cod) {
            return;
        }
        if (! $this->gateway->supports($method->value)) {
            throw new ApiException('This payment method is not available.', 422, ['payment_method' => ['This payment method is not available.']]);
        }
        if ($this->gateway->isMock() && app()->isProduction()) {
            throw new ApiException('Online payment is not available yet. Please choose cash on delivery.', 422, ['payment_method' => ['Online payment is not available yet.']]);
        }
    }

    /** @return array<string,mixed> honest description of what will happen with the money */
    public function paymentInfo(PaymentMethod $method): array
    {
        if ($method === PaymentMethod::Cod) {
            return ['method' => 'cod', 'status' => 'pending', 'is_mock' => false, 'message' => 'Pay in cash when the order is delivered.'];
        }

        return $this->gateway->isMock()
            ? ['method' => $method->value, 'status' => 'pending', 'is_mock' => true, 'message' => 'Development mode: no real payment is collected. The order will stay "payment pending".']
            : ['method' => $method->value, 'status' => 'pending', 'is_mock' => false, 'message' => 'Payment is pending until the payment provider confirms it.'];
    }
}
