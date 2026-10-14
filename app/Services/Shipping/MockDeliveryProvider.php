<?php

namespace App\Services\Shipping;

use App\Contracts\DeliveryProviderInterface;
use App\DTOs\Shipping\PickupConfirmation;
use App\DTOs\Shipping\ShipmentBooking;
use App\DTOs\Shipping\ShipmentRequest;
use App\DTOs\Shipping\ShippingLabel;
use App\DTOs\Shipping\TrackingEvent;
use App\Exceptions\ApiException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * *** MOCK *** Not a courier. No shipment is booked anywhere; nothing is picked up.
 * Replace by implementing DeliveryProviderInterface for the real courier and changing
 * DELIVERY_PROVIDER (see VistaroServiceProvider). Every DTO it returns has isMock = true,
 * AWBs are prefixed "MOCK-", and the label says so, so a mock can never be mistaken for a booking.
 */
final class MockDeliveryProvider implements DeliveryProviderInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function isMock(): bool
    {
        return true;
    }

    public function createShipment(ShipmentRequest $request): ShipmentBooking
    {
        return new ShipmentBooking(
            awb: 'MOCK-' . strtoupper(Str::random(10)),
            carrier: 'MOCK COURIER (simulated)',
            isMock: true,
        );
    }

    public function requestPickup(string $awb, ?DateTimeInterface $preferredAt = null): PickupConfirmation
    {
        return new PickupConfirmation($awb, 'MOCK-PKP-' . strtoupper(Str::random(6)), $preferredAt, true);
    }

    public function getLabel(string $awb): ShippingLabel
    {
        $e = htmlspecialchars($awb, ENT_QUOTES);
        $html = "<!doctype html><meta charset=\"utf-8\"><title>MOCK label</title>"
            . "<div style=\"font:16px sans-serif;border:2px dashed #b00;padding:24px;max-width:420px\">"
            . "<b style=\"color:#b00\">MOCK LABEL &mdash; NOT A REAL COURIER BOOKING</b><p>AWB: {$e}</p></div>";

        return new ShippingLabel($awb, 'text/html', $html, true);
    }

    public function track(string $awb): array
    {
        return [new TrackingEvent(
            externalId: 'mock-booked-' . $awb,
            awb: $awb,
            status: 'booked',
            occurredAt: new DateTimeImmutable(),
            description: 'Simulated booking (mock provider). No real shipment exists.',
        )];
    }

    public function cancelShipment(string $awb): bool
    {
        return true;
    }

    /**
     * Mock webhook format (for exercising the pipeline in dev/tests):
     *   header X-Signature = hex hmac-sha256(rawBody, DELIVERY_WEBHOOK_SECRET)
     *   body {"events":[{"id","awb","status","at","location","description"}]}
     */
    public function parseWebhook(string $rawBody, array $headers): array
    {
        $secret = (string) config('vistaro.shipping.webhook_secret');
        $given = $headers['x-signature'] ?? $headers['X-Signature'] ?? '';
        $given = is_array($given) ? (string) ($given[0] ?? '') : (string) $given;

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), $given)) {
            throw new ApiException('Invalid webhook signature.', 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! isset($payload['events']) || ! is_array($payload['events'])) {
            throw new ApiException('Malformed webhook payload.', 422);
        }

        $events = [];
        foreach ($payload['events'] as $e) {
            if (! isset($e['id'], $e['awb'], $e['status'], $e['at'])) {
                continue;
            }
            $events[] = new TrackingEvent(
                (string) $e['id'],
                (string) $e['awb'],
                (string) $e['status'],
                new DateTimeImmutable((string) $e['at']),
                $e['location'] ?? null,
                $e['description'] ?? null,
            );
        }

        return $events;
    }
}
