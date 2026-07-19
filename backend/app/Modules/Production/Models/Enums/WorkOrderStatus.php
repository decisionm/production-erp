<?php

namespace App\Modules\Production\Models\Enums;

enum WorkOrderStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case Completed = 'completed';
}
