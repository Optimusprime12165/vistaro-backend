<?php

namespace App\Enums;

/**
 * Fulfilment lifecycle. Payment state is separate (PaymentStatus).
 * The allowed-transition map is added in Phase 7 together with the state-machine service.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Packed = 'packed';
    case PickupRequested = 'pickup_requested';
    case Shipped = 'shipped';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case ReturnRequested = 'return_requested';
    case Returned = 'returned';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case DeliveryFailed = 'delivery_failed';

    /**
     * The ONLY allowed next statuses (the order state machine). Anything else is refused with HTTP 422.
     * Cancelled and Refunded are final. (pickup_requested can only be reached through the pickup endpoint,
     * which books the shipment; the generic transition endpoint refuses it.)
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled],
            self::Processing => [self::Packed, self::Cancelled],
            self::Packed => [self::PickupRequested, self::Cancelled],
            self::PickupRequested => [self::Shipped, self::Cancelled],
            self::Shipped => [self::OutForDelivery, self::DeliveryFailed],
            self::OutForDelivery => [self::Delivered, self::DeliveryFailed],
            self::DeliveryFailed => [self::OutForDelivery, self::Returned],
            self::Delivered => [self::ReturnRequested],
            self::ReturnRequested => [self::Returned],
            self::Returned => [self::RefundPending],
            self::RefundPending => [self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** Targets that need the `admin` role (staff may not cancel, receive returns or refund). */
    public function requiresAdminRole(): bool
    {
        return in_array($this, [self::Cancelled, self::Returned, self::RefundPending, self::Refunded], true);
    }

    /** Customer may cancel until the order has been packed. (Full admin transition map: Phase 7.) */
    public function canCustomerCancel(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Processing], true);
    }

    public function canCustomerReturn(): bool
    {
        return $this === self::Delivered;
    }
}
