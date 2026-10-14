<?php

namespace App\DTOs\Shipping;

final class ShipmentBooking
{
    public function __construct(
        public readonly string $awb,
        public readonly string $carrier,
        public readonly bool $isMock,
        public readonly ?string $externalReference = null,
    ) {
    }
}
