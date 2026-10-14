<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Initial = 'initial';
    case Adjustment = 'adjustment';          // admin edit
    case OrderReserved = 'order_reserved';   // available -> reserved
    case OrderReleased = 'order_released';   // reserved -> available (cancel before dispatch)
    case OrderFulfilled = 'order_fulfilled'; // reserved consumed
    case OrderReturned = 'order_returned';   // back into available
}
