<?php

namespace App\Services\Fulfilment;

use App\Contracts\DeliveryProviderInterface;
use App\DTOs\Shipping\ShipmentRequest;
use App\DTOs\Shipping\ShippingLabel;
use App\DTOs\Shipping\TrackingEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Everything that talks to the courier (through DeliveryProviderInterface; the mock today, a real provider later)
 * and everything that turns courier events into order progress. Provider specifics never reach controllers.
 *
 * NOTE: with the mock the provider call is instant. A real HTTP courier should be called before opening the
 * transaction (or with a short timeout), because the order row stays locked meanwhile.
 */
final class ShipmentService
{
    public function __construct(
        private readonly DeliveryProviderInterface $courier,
        private readonly FulfilmentService $fulfilment,
    ) {
    }

    /** Book the shipment (AWB) and request the pickup for a PACKED order, then move it to pickup_requested. */
    public function requestPickup(int $orderId, Admin $actor, ?DateTimeInterface $preferredAt = null): Order
    {
        return DB::transaction(function () use ($orderId, $actor, $preferredAt) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (! $order->status->canTransitionTo(OrderStatus::PickupRequested)) {
                throw new InvalidStateTransitionException($order->status->value, OrderStatus::PickupRequested->value);
            }
            if (Shipment::query()->where(['order_id' => $order->id, 'type' => 'forward'])->exists()) {
                throw new ApiException('A shipment already exists for this order.', 409);
            }

            $order->load(['items', 'address']);
            $cod = $order->payment_method === PaymentMethod::Cod;

            $booking = $this->courier->createShipment(new ShipmentRequest(
                orderNumber: $order->order_number,
                address: [
                    'name' => $order->address->name, 'phone' => $order->address->phone, 'line1' => $order->address->line1,
                    'landmark' => (string) $order->address->landmark, 'city' => $order->address->city,
                    'state' => $order->address->state, 'pincode' => $order->address->pincode,
                ],
                items: $order->items->map(fn ($i) => ['name' => $i->product_name, 'sku' => (string) $i->sku, 'qty' => $i->quantity])->all(),
                declaredValue: (string) $order->total,
                cod: $cod,
                codAmount: $cod ? (string) $order->total : '0.00',
            ));
            $pickup = $this->courier->requestPickup($booking->awb, $preferredAt);

            $shipment = Shipment::query()->create([
                'order_id' => $order->id, 'type' => 'forward', 'provider' => $this->courier->name(), 'is_mock' => $booking->isMock,
                'awb' => $booking->awb, 'carrier' => $booking->carrier, 'status' => ShipmentStatus::PickupRequested,
                'pickup_reference' => $pickup->pickupReference, 'pickup_requested_at' => now(),
            ]);
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id, 'external_id' => "booked-{$booking->awb}", 'status' => 'booked', 'occurred_at' => now(),
                'description' => $booking->isMock ? 'Simulated booking (mock courier). No real shipment exists.' : 'Shipment booked.',
            ]);

            return $this->fulfilment->transition($order, OrderStatus::PickupRequested, $actor, 'Pickup requested, AWB ' . $booking->awb, ['internal' => true]);
        }, 3);
    }

    public function label(Order $order): ShippingLabel
    {
        $shipment = Shipment::query()->where(['order_id' => $order->id, 'type' => 'forward'])->first();

        if (! $shipment || $shipment->awb === null || $shipment->status === ShipmentStatus::Cancelled) {
            throw new ApiException('This order has no active shipment, so there is no label.', 409);
        }

        return $this->courier->getLabel($shipment->awb);
    }

    /** Pull the courier's current events for this order's shipment and apply them (idempotent). @return array{applied:int,duplicates:int} */
    public function sync(Order $order): array
    {
        $shipment = Shipment::query()->where(['order_id' => $order->id, 'type' => 'forward'])->first();
        if (! $shipment || $shipment->awb === null) {
            throw new ApiException('This order has no shipment to track.', 409);
        }

        return $this->ingest($shipment, $this->courier->track($shipment->awb));
    }

    /**
     * Verified webhook payload -> events grouped per AWB. Unknown AWBs are counted and ignored (never an error:
     * the courier must not retry forever).
     *
     * @param list<TrackingEvent> $events
     * @return array{applied:int,duplicates:int,unknown:int}
     */
    public function ingestWebhook(array $events): array
    {
        $totals = ['applied' => 0, 'duplicates' => 0, 'unknown' => 0];

        foreach (collect($events)->groupBy('awb') as $awb => $group) {
            $shipment = Shipment::query()->where('awb', $awb)->first();
            if (! $shipment) {
                $totals['unknown'] += $group->count();
                continue;
            }
            $r = $this->ingest($shipment, $group->all());
            $totals['applied'] += $r['applied'];
            $totals['duplicates'] += $r['duplicates'];
        }

        return $totals;
    }

    /**
     * Store each NEW event (the unique (shipment, external_id) key makes duplicates harmless, even concurrent ones)
     * and let it move the order forward when that is a valid step. Stale / out-of-order events are stored but change nothing.
     *
     * @param list<TrackingEvent> $events
     * @return array{applied:int,duplicates:int}
     */
    public function ingest(Shipment $shipment, array $events): array
    {
        usort($events, fn (TrackingEvent $a, TrackingEvent $b) => $a->occurredAt <=> $b->occurredAt);
        $applied = $duplicates = 0;

        DB::transaction(function () use ($shipment, $events, &$applied, &$duplicates) {
            Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();   // serialise with admins, customer and other webhooks

            foreach ($events as $e) {
                $stored = ShipmentEvent::query()->insertOrIgnore([
                    'shipment_id' => $shipment->id, 'external_id' => $e->externalId, 'status' => $e->status,
                    'location' => $e->location, 'description' => $e->description !== null ? mb_substr($e->description, 0, 500) : null,
                    'occurred_at' => $e->occurredAt->format('Y-m-d H:i:s'), 'created_at' => now(),
                ]);
                if ($stored === 0) {
                    $duplicates++;
                    continue;
                }
                $applied++;
                $this->progress($shipment->order_id, $e);
            }
        }, 3);

        return ['applied' => $applied, 'duplicates' => $duplicates];
    }

    // ---------------------------------------------------------------- internals

    private function progress(int $orderId, TrackingEvent $event): void
    {
        $target = match ($event->status) {
            'picked_up', 'in_transit' => OrderStatus::Shipped,
            'out_for_delivery' => OrderStatus::OutForDelivery,
            'delivered' => OrderStatus::Delivered,
            'failed' => OrderStatus::DeliveryFailed,
            default => null,           // booked / returned: recorded only. A returned parcel is received (and restocked) by an admin.
        };
        if ($target === null) {
            return;
        }

        $current = Order::query()->whereKey($orderId)->value('status');
        $path = $this->pathTo(OrderStatus::from($current instanceof OrderStatus ? $current->value : $current), $target);

        foreach ($path ?? [] as $step) {
            $this->fulfilment->transition($orderId, $step, null, 'Courier update: ' . ($event->description ?: $event->status));
        }
    }

    /** Shortest valid chain of statuses (couriers skip steps), or null when the event is stale for the order. @return list<OrderStatus>|null */
    private function pathTo(OrderStatus $from, OrderStatus $to): ?array
    {
        if ($from->canTransitionTo($to)) {
            return [$to];
        }
        foreach ([OrderStatus::Shipped, OrderStatus::OutForDelivery] as $via) {
            if ($from->canTransitionTo($via) && $via->canTransitionTo($to)) {
                return [$via, $to];
            }
        }

        return $from === OrderStatus::PickupRequested && $to === OrderStatus::Delivered
            ? [OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered]
            : null;
    }
}
