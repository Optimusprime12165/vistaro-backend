<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case Created = 'created';
    case PickupRequested = 'pickup_requested';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
}
