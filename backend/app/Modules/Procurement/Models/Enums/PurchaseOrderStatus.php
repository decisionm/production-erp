<?php

namespace App\Modules\Procurement\Models\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
