<?php

namespace App\Modules\Maintenance\Models\Enums;

enum MaintenanceWorkOrderStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
