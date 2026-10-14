<?php

namespace App\Contracts;

use App\DTOs\Shipping\PickupConfirmation;
use App\DTOs\Shipping\ShipmentBooking;
use App\DTOs\Shipping\ShipmentRequest;
use App\DTOs\Shipping\ShippingLabel;
use App\DTOs\Shipping\TrackingEvent;
use DateTimeInterface;

/**
 * Courier abstraction. Provider-specific HTTP, auth and payload shapes stay inside implementations;
 * order services only see these DTOs.
 */
interface DeliveryProviderInterface
{
    public function name(): string;

    /** True for MockDeliveryProvider: shipments are simulated, NOT real courier bookings. */
    public function isMock(): bool;

    public function createShipment(ShipmentRequest $request): ShipmentBooking;

    public function requestPickup(string $awb, ?DateTimeInterface $preferredAt = null): PickupConfirmation;

    public function getLabel(string $awb): ShippingLabel;

    /** @return list<TrackingEvent> oldest first */
    public function track(string $awb): array;

    public function cancelShipment(string $awb): bool;

    /**
     * Verify the webhook signature and translate the payload.
     * Must throw App\Exceptions\ApiException (401) on an invalid signature.
     *
     * @param array<string,mixed> $headers
     * @return list<TrackingEvent> each carrying a stable external id for de-duplication
     */
    public function parseWebhook(string $rawBody, array $headers): array;
}
