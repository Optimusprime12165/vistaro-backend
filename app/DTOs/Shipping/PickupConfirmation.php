<?php

namespace App\DTOs\Shipping;

use DateTimeInterface;

final class PickupConfirmation
{
    public function __construct(
        public readonly string $awb,
        public readonly string $pickupReference,
        public readonly ?DateTimeInterface $scheduledAt,
        public readonly bool $isMock,
    ) {
    }
}
