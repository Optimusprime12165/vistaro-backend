<?php

namespace App\Enums;

/**
 * WHERE a stock change came from (recorded on inventory_movements.location). It is NOT a separate stock:
 * both values change the same shared product_sizes.quantity.
 *   online  = the online order flow / admin edits made from the online side
 *   offline = a manual adjustment for the physical shop (e.g. "-2" after 2 pieces were sold over the counter)
 */
enum InventoryLocation: string
{
    case Online = 'online';
    case Offline = 'offline';
}
