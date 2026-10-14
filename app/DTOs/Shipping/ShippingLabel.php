<?php

namespace App\DTOs\Shipping;

final class ShippingLabel
{
    public function __construct(
        public readonly string $awb,
        public readonly string $contentType,
        public readonly string $content,
        public readonly bool $isMock,
    ) {
    }
}
