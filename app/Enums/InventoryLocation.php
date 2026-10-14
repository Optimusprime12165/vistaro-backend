<?php

namespace App\Enums;

enum InventoryLocation: string
{
    case Online = 'online';
    case Offline = 'offline';
}
