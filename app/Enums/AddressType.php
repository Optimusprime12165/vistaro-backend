<?php

namespace App\Enums;

enum AddressType: string
{
    case Home = 'home';
    case Work = 'work';
    case Other = 'other';
}
