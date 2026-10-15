<?php

namespace App\Services\Fulfilment;

use App\Contracts\DeliveryProviderInterface;
use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Shipment;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * The single place where an order's status changes (admin actions AND courier updates). Each transition:
 *  - locks the order row, so two admins / a webhook / the customer can never apply conflicting changes
 *    (the loser sees the new status and gets HTTP 422),
 *  - is validated against OrderStatus::next() (the state machine),
 *  - runs its side effects (stock, shipment, payment) in the SAME transaction,
 *  - appends an order_status_histories row with actor, time and reason.
 *
 * Stock life cycle:  place -> reserve | cancel (before shipping) -> release | Shipped -> reservation consumed
 *                    | Returned -> optionally back on sale (the admin decides).
 */
final class FulfilmentService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DeliveryProviderInterface $courier,
    ) {
    }

    /**
     * @param array{restock?:bool,internal?:bool} $opts  restock: required for "returned"; internal: set by ShipmentService for pickup_requested
     * @param Admin|null $actor null = the system (courier update)
     */
    public function transition(int|Order $order, OrderStatus $to, ?Admin $actor = null, ?string $reason = null, array $opts = []): Order
    {
        $id = $order instanceof Order ? $order->id : $order;

        return DB::transaction(function () use ($id, $to, $actor, $reason, $opts) {
            $order = Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidStateTransitionException($from->value, $to->value);
            }
            if ($to === OrderStatus::PickupRequested && empty($opts['internal'])) {
                throw new ApiException('Use the pickup endpoint to book the shipment.', 422, ['status' => ['pickup_requested is set by POST /admin/orders/{id}/pickup.']]);
            }
            if ($actor !== null && $to->requiresAdminRole() && ! $actor->hasRole(AdminRole::Admin)) {
                throw new ApiException('You do not have permission to perform this action.', 403);
            }

            match ($to) {
                OrderStatus::Shipped => $this->onShipped($order, $actor),
                OrderStatus::OutForDelivery => $this->setShipment($order, ShipmentStatus::OutForDelivery),
                OrderStatus::DeliveryFailed => $this->setShipment($order, ShipmentStatus::Failed),
                OrderStatus::Delivered => $this->onDelivered($order),
                OrderStatus::Cancelled => $this->onCancelled($order, $actor),
                OrderStatus::Returned => $this->onReturned($order, $actor, $opts),
                OrderStatus::RefundPending => $this->onRefundPending($order),
                OrderStatus::Refunded => $this->onRefunded($order),
                default => null,                                   // confirmed, processing, packed, return_requested, pickup_requested
            };

            $order->forceFill(['status' => $to])->save();

            OrderStatusHistory::query()->create([
                'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
                'actor_type' => $actor ? 'admin' : 'system', 'actor_id' => $actor?->id,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            ]);

            return $order->refresh();
        }, 3);
    }

    // ------------------------------------------------------------ side effects

    private function onShipped(Order $order, ?Admin $actor): void
    {
        $shipment = $this->shipment($order);
        if (! $shipment || $shipment->awb === null || $shipment->status !== ShipmentStatus::PickupRequested) {
            throw new ApiException('Request a pickup before shipping this order.', 422, ['status' => ['No booked shipment for this order.']]);
        }

        foreach ($this->unitsPerSize($order) as $productSizeId => $qty) {            // the reservation becomes a sale
            $this->inventory->consumeReserved($productSizeId, $qty, 'order', $order->id, $actor);
        }

        $shipment->forceFill(['status' => ShipmentStatus::InTransit, 'picked_up_at' => $shipment->picked_up_at ?? now(), 'shipped_at' => now()])->save();
    }

    private function onDelivered(Order $order): void
    {
        $this->setShipment($order, ShipmentStatus::Delivered, ['delivered_at' => now()]);

        // Cash on delivery: the courier collected the money, so the payment is now really paid.
        // Online (UPI/card) payments are NEVER marked paid here: only a provider confirmation can do that.
        if ($order->payment_method === PaymentMethod::Cod && $order->payment_status === PaymentStatus::Pending) {
            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
            $order->payments()->where('method', PaymentMethod::Cod->value)->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Paid->value, 'paid_at' => now()]);
        }
    }

    private function onCancelled(Order $order, ?Admin $actor): void
    {
        $shipment = $this->shipment($order);
        if ($shipment && $shipment->awb !== null && $shipment->status !== ShipmentStatus::Cancelled) {
            if (! $this->courier->cancelShipment($shipment->awb)) {
                throw new ApiException('The courier could not cancel the shipment, so the order was not cancelled.', 409);
            }
            $shipment->forceFill(['status' => ShipmentStatus::Cancelled])->save();
        }

        foreach ($this->unitsPerSize($order) as $productSizeId => $qty) {            // cancellation is only possible before shipping: still reserved
            $this->inventory->release($productSizeId, $qty, 'order', $order->id, $actor, 'Cancelled by admin');
        }

        $next = $order->payment_status === PaymentStatus::Paid ? PaymentStatus::RefundPending : PaymentStatus::Cancelled;
        $order->forceFill(['payment_status' => $next])->save();
        $order->payments()->where('status', '!=', PaymentStatus::Refunded->value)->update(['status' => $next->value]);
    }

    /** @param array{restock?:bool} $opts */
    private function onReturned(Order $order, ?Admin $actor, array $opts): void
    {
        if (! array_key_exists('restock', $opts) || ! is_bool($opts['restock'])) {
            throw new ApiException('Say whether the returned items go back on sale.', 422, ['restock' => ['restock (true/false) is required when receiving a return.']]);
        }

        if ($opts['restock']) {
            foreach ($this->unitsPerSize($order) as $productSizeId => $qty) {
                $this->inventory->returnToStock($productSizeId, $qty, 'order', $order->id, $actor, 'Return received and restocked');
            }
        }

        $this->setShipment($order, ShipmentStatus::Returned);
    }

    private function onRefundPending(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            $order->forceFill(['payment_status' => PaymentStatus::RefundPending])->save();
            $order->payments()->where('status', PaymentStatus::Paid->value)->update(['status' => PaymentStatus::RefundPending->value]);
        }
    }

    /** Manual refund: the admin has paid the customer outside the system (there is no gateway) and records it here. */
    private function onRefunded(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::RefundPending) {
            $order->forceFill(['payment_status' => PaymentStatus::Refunded])->save();
            $order->payments()->where('status', PaymentStatus::RefundPending->value)->update(['status' => PaymentStatus::Refunded->value]);
        }
    }

    // ----------------------------------------------------------------- helpers

    private function shipment(Order $order): ?Shipment
    {
        return Shipment::query()->where(['order_id' => $order->id, 'type' => 'forward'])->lockForUpdate()->first();
    }

    /** @param array<string,mixed> $extra */
    private function setShipment(Order $order, ShipmentStatus $status, array $extra = []): void
    {
        $this->shipment($order)?->forceFill(['status' => $status] + $extra)->save();
    }

    /** @return array<int,int> product_size_id => units, ascending ids (stable lock order); lines whose product was deleted are skipped */
    private function unitsPerSize(Order $order): array
    {
        $units = $order->items()->whereNotNull('product_size_id')->get()->groupBy('product_size_id')->map(fn ($g) => (int) $g->sum('quantity'))->all();
        ksort($units);

        return $units;
    }
}
