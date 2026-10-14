<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
}
