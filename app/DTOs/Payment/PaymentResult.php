<?php

namespace App\DTOs\Payment;

final class PaymentResult
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly string $gateway,
        public readonly bool $isMock,
        public readonly ?string $reference = null,
        public readonly ?string $redirectUrl = null,
    ) {
    }
}
