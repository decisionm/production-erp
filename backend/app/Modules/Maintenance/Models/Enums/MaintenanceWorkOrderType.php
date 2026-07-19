<?php

namespace App\Modules\Maintenance\Models\Enums;

enum MaintenanceWorkOrderType: string
{
    case Preventive = 'preventive';
    case Corrective = 'corrective';
}
