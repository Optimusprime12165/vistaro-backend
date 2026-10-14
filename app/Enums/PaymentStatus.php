<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';   // order cancelled before any money was collected
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
}
