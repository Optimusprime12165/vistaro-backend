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
}
