<?php

namespace App\DTOs\Shipping;

final class ShipmentRequest
{
    /**
     * @param array<string,string> $address name, phone, line1, landmark, city, state, pincode
     * @param list<array{name:string,sku:string,qty:int}> $items
     */
    public function __construct(
        public readonly string $orderNumber,
        public readonly array $address,
        public readonly array $items,
        public readonly string $declaredValue,
        public readonly bool $cod,
        public readonly string $codAmount = '0.00',
    ) {
    }
}
