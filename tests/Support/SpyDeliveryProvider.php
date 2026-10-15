<?php

namespace Tests\Support;

use App\Contracts\DeliveryProviderInterface;
use App\DTOs\Shipping\PickupConfirmation;
use App\DTOs\Shipping\ShipmentBooking;
use App\DTOs\Shipping\ShipmentRequest;
use App\DTOs\Shipping\ShippingLabel;
use App\Services\Shipping\MockDeliveryProvider;
use DateTimeInterface;

/** Wraps the real mock courier, records every call and can be told to fail a cancel. */
final class SpyDeliveryProvider implements DeliveryProviderInterface
{
    public array $calls = [];
    public bool $cancelSucceeds = true;
    public ?ShipmentRequest $lastRequest = null;
    private MockDeliveryProvider $inner;

    public function __construct()
    {
        $this->inner = new MockDeliveryProvider();
    }

    public function name(): string { return $this->inner->name(); }
    public function isMock(): bool { return true; }

    public function createShipment(ShipmentRequest $request): ShipmentBooking
    {
        $this->calls[] = 'create';
        $this->lastRequest = $request;

        return $this->inner->createShipment($request);
    }

    public function requestPickup(string $awb, ?DateTimeInterface $preferredAt = null): PickupConfirmation
    {
        $this->calls[] = 'pickup';

        return $this->inner->requestPickup($awb, $preferredAt);
    }

    public function getLabel(string $awb): ShippingLabel { return $this->inner->getLabel($awb); }
    public function track(string $awb): array { return $this->inner->track($awb); }

    public function cancelShipment(string $awb): bool
    {
        $this->calls[] = 'cancel';

        return $this->cancelSucceeds;
    }

    public function parseWebhook(string $rawBody, array $headers): array { return $this->inner->parseWebhook($rawBody, $headers); }

    public function count(string $call): int { return count(array_keys($this->calls, $call, true)); }
}
