<?php

namespace App\Modules\Sales\Models\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyDelivered = 'partially_delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
