<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\Payment\PaymentResult;
use Illuminate\Support\Str;

/**
 * *** MOCK *** No money moves. It never returns PAID: orders paid by "UPI/card" stay `pending`
 * until a real gateway confirms. Replace with a real PaymentGatewayInterface implementation.
 * Never receives card numbers or CVVs; a real integration redirects to / embeds the provider's hosted checkout.
 */
final class MockPaymentGateway implements PaymentGatewayInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function isMock(): bool
    {
        return true;
    }

    public function supports(string $method): bool
    {
        return in_array($method, ['upi', 'card'], true);
    }

    public function initiate(string $orderNumber, string $amount, string $method): PaymentResult
    {
        return new PaymentResult(PaymentResult::PENDING, 'mock', true, 'MOCKPAY-' . strtoupper(Str::random(8)));
    }
}
