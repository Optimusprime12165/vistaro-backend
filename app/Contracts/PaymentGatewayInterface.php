<?php

namespace App\Contracts;

use App\DTOs\Payment\PaymentResult;

/**
 * Online payment (UPI/card). COD does not go through a gateway.
 * Card numbers/CVVs must never reach this layer: a real provider uses a hosted/tokenised checkout.
 */
interface PaymentGatewayInterface
{
    public function name(): string;

    /** True for the development stub. A mock never means money moved. */
    public function isMock(): bool;

    public function supports(string $method): bool;

    /** @param string $amount decimal string, e.g. "1999.00" */
    public function initiate(string $orderNumber, string $amount, string $method): PaymentResult;
}
