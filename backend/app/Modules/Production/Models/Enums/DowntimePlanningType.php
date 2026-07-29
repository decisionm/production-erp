<?php

namespace App\Modules\Production\Models\Enums;

enum DowntimePlanningType: string
{
    case Planned = 'planned';
    case Unplanned = 'unplanned';
}
