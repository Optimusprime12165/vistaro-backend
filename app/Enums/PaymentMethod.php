<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cod = 'cod';
    case Upi = 'upi';
    case Card = 'card';
}
