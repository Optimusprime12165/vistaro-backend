<?php

namespace App\DTOs\Shipping;

use DateTimeInterface;

final class TrackingEvent
{
    public function __construct(
        public readonly string $externalId,   // stable; (awb, externalId) unique => webhook de-duplication
        public readonly string $awb,
        public readonly string $status,       // booked|picked_up|in_transit|out_for_delivery|delivered|failed|returned
        public readonly DateTimeInterface $occurredAt,
        public readonly ?string $location = null,
        public readonly ?string $description = null,
    ) {
    }
}
